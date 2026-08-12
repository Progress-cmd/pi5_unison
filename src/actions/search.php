<?php
/**
 * Recherche globale : titres, artistes et playlists.
 *
 * Meilisearch fournit la pertinence et les positions de correspondance ; la
 * base fournit tout le détail affiché (pochette, artistes, durée, genres,
 * étiquettes, écoutes…). Les playlists, qui ne sont pas indexées, sont
 * cherchées directement en base.
 *
 * Entrée POST : search-entry, [playlist_id]
 * Sortie JSON : { musiques, artistes, playlists, totaux }
 */
include_once "../includes/auth.php";
include_once "../includes/config.php";
include_once "../includes/viewMode.php";

exigerConnexion(true);

require '../../vendor/autoload.php';
use Meilisearch\Client;

header('Content-Type: application/json');

$q = trim((string) filter_input(INPUT_POST, 'search-entry', FILTER_DEFAULT));
$playlist_id = filter_input(INPUT_POST, 'playlist_id', FILTER_VALIDATE_INT);

if ($q === '') {
    // Totaux explicitement à zéro : un tableau vide sortirait en `[]` côté
    // JSON, et le client attend un objet avec ses quatre compteurs.
    echo json_encode([
        'musiques'  => [],
        'artistes'  => [],
        'playlists' => [],
        'totaux'    => ['musiques' => 0, 'artistes' => 0, 'playlists' => 0, 'tout' => 0],
    ]);
    exit;
}

/*
 * Marqueurs de surlignage. Ce sont des caractères de contrôle : ils ne peuvent
 * pas apparaître dans un titre, donc le client peut échapper le texte en HTML
 * puis remplacer ces marqueurs sans risque d'injection.
 */
const HL_DEBUT = "\x01";
const HL_FIN   = "\x02";

$pdo    = Config::getConnection();
$userId = (int) ($_SESSION['user']['id'] ?? 0);

$client = new Client('http://ms:7700', getenv('MS_PASS') ?: null);

// Index dédiés en démonstration : la recherche ne doit pas laisser filtrer un
// titre ou un artiste du catalogue personnel.
$optionsMeili = [
    'limit'                => 40,
    'attributesToHighlight' => ['*'],
    'highlightPreTag'      => HL_DEBUT,
    'highlightPostTag'     => HL_FIN,
];

$hitsMusiques = $client->index(Config::indexMeili('musiques'))->search($q, $optionsMeili)->getHits();
$hitsArtistes = $client->index(Config::indexMeili('artists'))->search($q, $optionsMeili)->getHits();

/** Construit la liste de paramètres nommés d'un IN (...) et les valeurs associées. */
function clauseIn(array $ids, string $prefixe): array
{
    $noms = [];
    $valeurs = [];
    foreach (array_values($ids) as $i => $id) {
        $noms[] = ":$prefixe$i";
        $valeurs[":$prefixe$i"] = $id;
    }
    return [implode(',', $noms), $valeurs];
}

/**
 * Regroupe les lignes d'une requête de liaison par identifiant parent.
 * Utilisé pour rattacher genres et étiquettes en une seule requête chacun.
 */
function grouperPar(array $lignes, string $cle): array
{
    $groupes = [];
    foreach ($lignes as $ligne) {
        $groupes[$ligne[$cle]][] = ['id' => (int) $ligne['id'], 'name' => $ligne['name']];
    }
    return $groupes;
}

// ---------------------------------------------------------------- TITRES

$musiques = [];
$idsMusiques = array_column($hitsMusiques, 'id_music');

if ($idsMusiques) {
    [$in, $params] = clauseIn($idsMusiques, 'm');

    // Détail des titres, avec les artistes agrégés et le compteur d'écoutes
    // personnel. Une seule requête pour l'ensemble des résultats.
    $req = $pdo->prepare("
        SELECT tracks.id, tracks.title, tracks.duration, tracks.img,
               GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artists_names,
               GROUP_CONCAT(DISTINCT artists.id   ORDER BY artists.name SEPARATOR ',')  AS artists_ids,
               COALESCE(nb_listen.nb, 0) AS nb_ecoutes
        FROM tracks
        LEFT JOIN artist__track ON artist__track.track_id = tracks.id
        LEFT JOIN artists       ON artists.id = artist__track.artist_id
        LEFT JOIN nb_listen     ON nb_listen.track_id = tracks.id AND nb_listen.user_id = :user
        WHERE tracks.id IN ($in)
        GROUP BY tracks.id, tracks.title, tracks.duration, tracks.img, nb_listen.nb
    ");
    $req->execute($params + [':user' => $userId]);
    $details = [];
    foreach ($req->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
        $details[(int) $ligne['id']] = $ligne;
    }

    // Genres
    $req = $pdo->prepare("
        SELECT track__genre.track_id AS parent, genres.id, genres.name
        FROM track__genre JOIN genres ON genres.id = track__genre.genre_id
        WHERE track__genre.track_id IN ($in)
    ");
    $req->execute($params);
    $genres = grouperPar($req->fetchAll(PDO::FETCH_ASSOC), 'parent');

    // Étiquettes
    $req = $pdo->prepare("
        SELECT tag__track.track_id AS parent, tags.id, tags.name
        FROM tag__track JOIN tags ON tags.id = tag__track.tag_id
        WHERE tag__track.track_id IN ($in)
    ");
    $req->execute($params);
    $tags = grouperPar($req->fetchAll(PDO::FETCH_ASSOC), 'parent');

    // Titres présents dans les favoris de l'utilisateur
    $req = $pdo->prepare("
        SELECT track__playlist.track_id
        FROM track__playlist
        JOIN playlists ON playlists.id = track__playlist.playlist_id
        WHERE playlists.name = 'Favorite Tracks' AND playlists.`created-by_id` = :user
          AND track__playlist.track_id IN ($in)
    ");
    $req->execute($params + [':user' => $userId]);
    $favoris = array_flip($req->fetchAll(PDO::FETCH_COLUMN));

    // Nombre de playlists contenant chaque titre (hors listes système)
    $req = $pdo->prepare("
        SELECT track__playlist.track_id AS parent, COUNT(*) AS n
        FROM track__playlist
        JOIN playlists ON playlists.id = track__playlist.playlist_id
        WHERE playlists.name NOT IN ('Wait Tracks', 'Favorite Tracks')
          AND track__playlist.track_id IN ($in)
        GROUP BY track__playlist.track_id
    ");
    $req->execute($params);
    $nbPlaylists = array_column($req->fetchAll(PDO::FETCH_ASSOC), 'n', 'parent');

    // Titres déjà présents dans la playlist en cours d'édition, le cas échéant
    $dejaDedans = [];
    if ($playlist_id) {
        $req = $pdo->prepare("SELECT track_id FROM track__playlist WHERE playlist_id = :playlist");
        $req->execute([':playlist' => $playlist_id]);
        $dejaDedans = array_flip($req->fetchAll(PDO::FETCH_COLUMN));
    }

    // On repart des hits Meilisearch pour conserver l'ordre de pertinence.
    foreach ($hitsMusiques as $hit) {
        $id = (int) $hit['id_music'];
        if (!isset($details[$id])) {
            continue; // indexé mais supprimé depuis : on ignore
        }
        $d = $details[$id];

        $musiques[] = [
            'id'            => $id,
            'title'         => $d['title'],
            'title_surligne'=> $hit['_formatted']['title_music'] ?? $d['title'],
            'duration'      => (int) $d['duration'],
            'img'           => $d['img'],
            'artists_names' => $d['artists_names'],
            'artists_ids'   => $d['artists_ids'] ? array_map('intval', explode(',', $d['artists_ids'])) : [],
            'genres'        => $genres[$id] ?? [],
            'tags'          => $tags[$id] ?? [],
            'nb_ecoutes'    => (int) $d['nb_ecoutes'],
            'nb_playlists'  => (int) ($nbPlaylists[$id] ?? 0),
            'favori'        => isset($favoris[$id]) ? 1 : 0,
            'in_playlist'   => $playlist_id ? (isset($dejaDedans[$id]) ? 1 : 0) : null,
        ];
    }
}

// -------------------------------------------------------------- ARTISTES

$artistes = [];
$idsArtistes = array_column($hitsArtistes, 'id_artist');

if ($idsArtistes) {
    [$in, $params] = clauseIn($idsArtistes, 'a');

    $req = $pdo->prepare("
        SELECT artists.id, artists.name, artists.img,
               COUNT(DISTINCT tracks.id) AS nb_titres,
               COALESCE(SUM(tracks.duration), 0) AS duree_totale
        FROM artists
        LEFT JOIN artist__track ON artist__track.artist_id = artists.id
        LEFT JOIN tracks        ON tracks.id = artist__track.track_id
        WHERE artists.id IN ($in)
        GROUP BY artists.id, artists.name, artists.img
    ");
    $req->execute($params);
    $details = [];
    foreach ($req->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
        $details[(int) $ligne['id']] = $ligne;
    }

    $req = $pdo->prepare("
        SELECT artist__genre.artist_id AS parent, genres.id, genres.name
        FROM artist__genre JOIN genres ON genres.id = artist__genre.genre_id
        WHERE artist__genre.artist_id IN ($in)
    ");
    $req->execute($params);
    $genresArtiste = grouperPar($req->fetchAll(PDO::FETCH_ASSOC), 'parent');

    foreach ($hitsArtistes as $hit) {
        $id = (int) $hit['id_artist'];
        if (!isset($details[$id])) {
            continue;
        }
        $d = $details[$id];

        $artistes[] = [
            'id'            => $id,
            'name'          => $d['name'],
            'name_surligne' => $hit['_formatted']['name_artist'] ?? $d['name'],
            'img'           => $d['img'],
            'nb_titres'     => (int) $d['nb_titres'],
            'duree_totale'  => (int) $d['duree_totale'],
            'genres'        => $genresArtiste[$id] ?? [],
        ];
    }
}

// ------------------------------------------------------------- PLAYLISTS

/*
 * Les playlists ne sont pas indexées dans Meilisearch : une recherche LIKE
 * suffit à leur volume, et évite d'avoir à maintenir un index de plus.
 * Le mode d'affichage personnel restreint la recherche aux siennes.
 */
$sqlPlaylists = "
    SELECT playlists.id, playlists.name, playlists.`created-by_id` AS auteur_id,
           users.username AS auteur, playlists.`updated-at` AS maj,
           COUNT(DISTINCT track__playlist.track_id) AS nb_titres,
           COALESCE(SUM(tracks.duration), 0) AS duree_totale
    FROM playlists
    LEFT JOIN users            ON users.id = playlists.`created-by_id`
    LEFT JOIN track__playlist  ON track__playlist.playlist_id = playlists.id
    LEFT JOIN tracks           ON tracks.id = track__playlist.track_id
    WHERE playlists.name LIKE :motif
      AND playlists.name NOT IN ('Wait Tracks', 'Favorite Tracks')
";
$paramsPlaylists = [':motif' => '%' . $q . '%'];

if (isPersonalView()) {
    $sqlPlaylists .= " AND playlists.`created-by_id` = :user";
    $paramsPlaylists[':user'] = $userId;
}

$sqlPlaylists .= "
    GROUP BY playlists.id, playlists.name, playlists.`created-by_id`, users.username, playlists.`updated-at`
    ORDER BY nb_titres DESC, playlists.name
    LIMIT 15
";

$req = $pdo->prepare($sqlPlaylists);
$req->execute($paramsPlaylists);
$lignesPlaylists = $req->fetchAll(PDO::FETCH_ASSOC);

$playlists = [];
if ($lignesPlaylists) {
    [$in, $params] = clauseIn(array_column($lignesPlaylists, 'id'), 'p');

    $req = $pdo->prepare("
        SELECT tag__playlist.playlist_id AS parent, tags.id, tags.name
        FROM tag__playlist JOIN tags ON tags.id = tag__playlist.tag_id
        WHERE tag__playlist.playlist_id IN ($in)
    ");
    $req->execute($params);
    $tagsPlaylist = grouperPar($req->fetchAll(PDO::FETCH_ASSOC), 'parent');

    // Surlignage reproduit côté serveur : la correspondance est un simple
    // « contient », on encadre donc l'occurrence avec les mêmes marqueurs.
    foreach ($lignesPlaylists as $l) {
        $id = (int) $l['id'];
        $position = mb_stripos($l['name'], $q);
        $surligne = $l['name'];
        if ($position !== false) {
            $surligne = mb_substr($l['name'], 0, $position)
                . HL_DEBUT . mb_substr($l['name'], $position, mb_strlen($q)) . HL_FIN
                . mb_substr($l['name'], $position + mb_strlen($q));
        }

        $playlists[] = [
            'id'            => $id,
            'name'          => $l['name'],
            'name_surligne' => $surligne,
            'auteur'        => $l['auteur'],
            'est_mienne'    => ((int) $l['auteur_id'] === $userId) ? 1 : 0,
            'maj'           => $l['maj'],
            'nb_titres'     => (int) $l['nb_titres'],
            'duree_totale'  => (int) $l['duree_totale'],
            'tags'          => $tagsPlaylist[$id] ?? [],
        ];
    }
}

echo json_encode([
    'musiques'  => $musiques,
    'artistes'  => $artistes,
    'playlists' => $playlists,
    'totaux'    => [
        'musiques'  => count($musiques),
        'artistes'  => count($artistes),
        'playlists' => count($playlists),
        'tout'      => count($musiques) + count($artistes) + count($playlists),
    ],
]);
