<?php
/**
 * Consultation paginée du catalogue, pour la page Contenu de l'administration.
 *
 * La page rendait la totalité des titres et des artistes, puis filtrait dans
 * le DOM. C'était tenable à quelques centaines de lignes ; ça ne l'est plus
 * quand la discothèque grandit — la page devient lourde à produire, lourde à
 * transmettre, et lente à filtrer.
 *
 * Même principe que la consultation du journal : une page à la fois, et la
 * recherche exécutée par la base plutôt que par le navigateur. C'est ce
 * second point qui compte le plus : filtrer dans le DOM ne pouvait, par
 * construction, trouver que ce qui était déjà chargé.
 */

/** Nombre de lignes par page. */
const CONTENU_PAR_PAGE = 50;

/**
 * Clause de recherche et ses paramètres.
 *
 * Le terme n'est jamais concaténé au SQL : il ne circule que comme valeur
 * liée. Les jokers de LIKE présents dans la saisie sont neutralisés, sans
 * quoi un simple « % » listerait tout et « _ » se comporterait en joker.
 */
function contenuRecherche(string $terme, array $colonnes): array
{
    $terme = trim($terme);

    if ($terme === '') {
        return ['', []];
    }

    $motif = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $terme) . '%';

    $morceaux = [];
    $parametres = [];

    foreach ($colonnes as $i => $colonne) {
        $nom = ':recherche' . $i;           // un marqueur par colonne : une
        $morceaux[] = "$colonne LIKE $nom ESCAPE '\\\\'";  // requête préparée
        $parametres[$nom] = $motif;         // native refuse un nom répété
    }

    return [' WHERE ' . implode(' OR ', $morceaux), $parametres];
}

/**
 * Une page de titres, avec leurs artistes.
 *
 * @return array{lignes: array, total: int, pages: int, page: int}
 */
function contenuTitres(PDO $pdo, string $terme = '', int $page = 1): array
{
    // La recherche porte sur le titre et sur le nom des artistes : c'est par
    // l'un ou l'autre qu'on cherche un morceau, jamais par son identifiant.
    [$where, $parametres] = contenuRecherche($terme, ['tracks.title', 'artists.name']);

    $req = $pdo->prepare(
        "SELECT COUNT(DISTINCT tracks.id)
           FROM tracks
           LEFT JOIN artist__track ON artist__track.track_id = tracks.id
           LEFT JOIN artists       ON artists.id = artist__track.artist_id" . $where
    );
    $req->execute($parametres);
    $total = (int) $req->fetchColumn();

    // LIMIT et OFFSET interpolés, jamais liés : en préparation native MariaDB
    // refuse un paramètre à cet endroit. Les deux sont des entiers calculés ici.
    $page   = max(1, min($page, max(1, (int) ceil($total / CONTENU_PAR_PAGE))));
    $offset = ($page - 1) * CONTENU_PAR_PAGE;

    $req = $pdo->prepare(
        "SELECT tracks.id, tracks.title, tracks.duration, tracks.file,
                GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artistes,
                users.username AS ajoute_par
           FROM tracks
           LEFT JOIN artist__track ON artist__track.track_id = tracks.id
           LEFT JOIN artists       ON artists.id = artist__track.artist_id
           LEFT JOIN users         ON users.id = tracks.`added-by_id`" . $where . "
          GROUP BY tracks.id, tracks.title, tracks.duration, tracks.file, users.username
          ORDER BY tracks.title
          LIMIT " . CONTENU_PAR_PAGE . " OFFSET " . $offset
    );
    $req->execute($parametres);

    return [
        'lignes' => $req->fetchAll(PDO::FETCH_ASSOC),
        'total'  => $total,
        'pages'  => max(1, (int) ceil($total / CONTENU_PAR_PAGE)),
        'page'   => $page,
    ];
}

/**
 * Une page d'artistes, avec leur nombre de titres.
 *
 * @return array{lignes: array, total: int, pages: int, page: int}
 */
function contenuArtistes(PDO $pdo, string $terme = '', int $page = 1): array
{
    [$where, $parametres] = contenuRecherche($terme, ['artists.name']);

    $req = $pdo->prepare("SELECT COUNT(*) FROM artists" . $where);
    $req->execute($parametres);
    $total = (int) $req->fetchColumn();

    $page   = max(1, min($page, max(1, (int) ceil($total / CONTENU_PAR_PAGE))));
    $offset = ($page - 1) * CONTENU_PAR_PAGE;

    $req = $pdo->prepare(
        "SELECT artists.id, artists.name, COUNT(artist__track.track_id) AS nb_titres
           FROM artists
           LEFT JOIN artist__track ON artist__track.artist_id = artists.id" . $where . "
          GROUP BY artists.id, artists.name
          ORDER BY nb_titres DESC, artists.name
          LIMIT " . CONTENU_PAR_PAGE . " OFFSET " . $offset
    );
    $req->execute($parametres);

    return [
        'lignes' => $req->fetchAll(PDO::FETCH_ASSOC),
        'total'  => $total,
        'pages'  => max(1, (int) ceil($total / CONTENU_PAR_PAGE)),
        'page'   => $page,
    ];
}
