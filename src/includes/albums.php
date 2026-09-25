<?php
/**
 * Albums : lecture, création et suppression.
 *
 * Un album est d'abord une provenance. Sa raison d'être n'est pas de grouper
 * des titres — les playlists le font déjà — mais de garder la trace de ce
 * qu'un import a fait entrer dans la discothèque, pour pouvoir le défaire
 * sans détruire ce qui existait avant.
 *
 * D'où les deux liens portés par `tracks`, qu'il ne faut jamais confondre :
 *   album_id         l'album auquel le titre appartient (affichage) ;
 *   album_source_id  l'album qui l'a fait entrer (droit de suppression).
 */

/** Titres d'un album, dans l'ordre de la galette. */
function albumTitres(PDO $pdo, int $albumId): array
{
    $req = $pdo->prepare("
        SELECT tracks.id, tracks.title, tracks.duration, tracks.img, tracks.track_number,
               tracks.album_source_id,
               GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artists_names
          FROM tracks
          LEFT JOIN artist__track ON artist__track.track_id = tracks.id
          LEFT JOIN artists       ON artists.id = artist__track.artist_id
         WHERE tracks.album_id = :album
         GROUP BY tracks.id, tracks.title, tracks.duration, tracks.img,
                  tracks.track_number, tracks.album_source_id
         ORDER BY tracks.track_number IS NULL, tracks.track_number, tracks.title
    ");
    $req->execute([':album' => $albumId]);

    return $req->fetchAll(PDO::FETCH_ASSOC);
}

/** Un album et son décompte, ou null. */
function albumDetail(PDO $pdo, int $albumId): ?array
{
    $req = $pdo->prepare("
        SELECT albums.id, albums.title, albums.annee, albums.img, albums.source_url,
               albums.`created-at`, artists.name AS artiste,
               (SELECT COUNT(*) FROM tracks WHERE tracks.album_id = albums.id) AS nb_titres
          FROM albums
          LEFT JOIN artists ON artists.id = albums.artist_id
         WHERE albums.id = :id
    ");
    $req->execute([':id' => $albumId]);

    return $req->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Liste des albums, du plus récemment importé au plus ancien.
 *
 * @param int $limite 0 pour tout rendre (la page « Voir tout »).
 */
function albumsLister(PDO $pdo, int $limite = 0): array
{
    // LIMIT n'accepte pas de paramètre lié en préparation native : la valeur
    // est bornée ici puis interpolée en entier.
    $clause = $limite > 0 ? ' LIMIT ' . max(1, min(200, $limite)) : '';

    return $pdo->query("
        SELECT albums.id, albums.title, albums.annee, albums.img,
               artists.name AS artiste,
               (SELECT COUNT(*) FROM tracks WHERE tracks.album_id = albums.id) AS nb_titres,
               (SELECT COALESCE(SUM(duration), 0) FROM tracks WHERE tracks.album_id = albums.id) AS duree
          FROM albums
          LEFT JOIN artists ON artists.id = albums.artist_id
         ORDER BY albums.`created-at` DESC, albums.id DESC" . $clause
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Supprime un album, et lui seul — ou avec les titres qu'il a amenés.
 *
 * C'est ici que se joue la garantie demandée : un titre n'est supprimé que si
 * `album_source_id` désigne CET album, c'est-à-dire s'il est entré dans la
 * discothèque par cet import. Tout le reste est détaché et conservé :
 *
 *   entré par cet album      → supprimé (base + fichier), si $avecTitres
 *   présent avant l'import   → détaché, conservé
 *   rattaché à la main       → détaché, conservé
 *
 * L'ordre compte : la liste des titres à supprimer est établie AVANT de
 * toucher à `albums`, car la contrainte ON DELETE SET NULL efface
 * `album_source_id` au moment même où la ligne disparaît. Interroger après
 * coup ne rendrait plus rien.
 *
 * @return array{success: bool, message: string, supprimes: int, detaches: int}
 */
function albumSupprimer(PDO $pdo, int $albumId, bool $avecTitres): array
{
    $album = albumDetail($pdo, $albumId);
    if (!$album) {
        return ['success' => false, 'message' => 'Album introuvable', 'supprimes' => 0, 'detaches' => 0];
    }

    $aSupprimer = [];

    if ($avecTitres) {
        $req = $pdo->prepare(
            "SELECT id, title, file FROM tracks WHERE album_source_id = :album"
        );
        $req->execute([':album' => $albumId]);
        $aSupprimer = $req->fetchAll(PDO::FETCH_ASSOC);
    }

    $req = $pdo->prepare("SELECT COUNT(*) FROM tracks WHERE album_id = :album");
    $req->execute([':album' => $albumId]);
    $totalRattaches = (int) $req->fetchColumn();

    $supprimes = 0;

    foreach ($aSupprimer as $titre) {
        // Le fichier d'abord : si la ligne partait la première, un échec de
        // suppression laisserait un fichier que plus rien ne désigne.
        $chemin = Config::cheminMusiques() . basename((string) $titre['file']);
        if (is_file($chemin)) {
            @unlink($chemin);
        }

        $req = $pdo->prepare("DELETE FROM tracks WHERE id = :id");
        $req->execute([':id' => (int) $titre['id']]);
        $supprimes++;
    }

    $req = $pdo->prepare("DELETE FROM albums WHERE id = :id");
    $req->execute([':id' => $albumId]);

    $detaches = max(0, $totalRattaches - $supprimes);

    journalInfo('contenu', 'album_supprime',
        'Album supprimé : ' . $album['title'],
        [
            'album_id'  => $albumId,
            'titre'     => $album['title'],
            'supprimes' => $supprimes,
            'detaches'  => $detaches,
        ]);

    return [
        'success'   => true,
        'message'   => 'Album supprimé',
        'supprimes' => $supprimes,
        'detaches'  => $detaches,
    ];
}

/**
 * Crée l'album s'il n'existe pas, ou retrouve celui déjà importé.
 *
 * `source_url` porte une contrainte d'unicité : réimporter le même lien ne
 * crée pas de doublon, il complète l'album existant. C'est le comportement
 * attendu quand un import s'est interrompu en cours de route.
 *
 * @return int l'identifiant de l'album
 */
function albumCreerOuRetrouver(PDO $pdo, string $titre, ?string $artiste,
                               ?string $sourceUrl, int $userId): int
{
    if ($sourceUrl !== null && $sourceUrl !== '') {
        $req = $pdo->prepare("SELECT id FROM albums WHERE source_url = :src");
        $req->execute([':src' => $sourceUrl]);
        $existant = $req->fetchColumn();
        if ($existant) {
            return (int) $existant;
        }
    }

    // L'artiste est rattaché s'il existe déjà ; sinon l'album s'en passe.
    // Le créer ici produirait des artistes sans aucun titre quand l'import
    // échoue juste après.
    $artisteId = null;
    if ($artiste !== null && trim($artiste) !== '') {
        $req = $pdo->prepare("SELECT id FROM artists WHERE name = :nom");
        $req->execute([':nom' => trim($artiste)]);
        $trouve = $req->fetchColumn();
        $artisteId = $trouve ? (int) $trouve : null;
    }

    $req = $pdo->prepare(
        "INSERT INTO albums (title, artist_id, source_url, `added-by_id`)
         VALUES (:titre, :artiste, :src, :user)"
    );
    $req->execute([
        ':titre'   => mb_substr($titre, 0, 150),
        ':artiste' => $artisteId,
        ':src'     => $sourceUrl ?: null,
        ':user'    => $userId,
    ]);

    $albumId = (int) $pdo->lastInsertId();

    journalInfo('contenu', 'album_cree', 'Album créé : ' . $titre,
        ['album_id' => $albumId, 'titre' => $titre, 'artiste' => $artiste, 'source' => $sourceUrl]);

    return $albumId;
}

/**
 * Rattache un titre à un album.
 *
 * `$estSource` dit si c'est CET import qui a fait entrer le titre dans la
 * discothèque. C'est la seule information qui autorisera plus tard la
 * suppression du fichier avec l'album — elle ne peut pas être devinée après
 * coup, d'où sa transmission explicite depuis l'import.
 *
 * Le rattachement ne réécrit jamais un album_source_id déjà posé : un titre
 * n'est entré qu'une fois, par un seul import.
 */
function albumRattacherTitre(PDO $pdo, int $trackId, int $albumId,
                             bool $estSource, ?int $piste = null): void
{
    $req = $pdo->prepare(
        "UPDATE tracks
            SET album_id = :album,
                track_number = COALESCE(:piste, track_number),
                album_source_id = CASE
                    WHEN :source = 1 AND album_source_id IS NULL THEN :album2
                    ELSE album_source_id
                END
          WHERE id = :track"
    );
    $req->execute([
        ':album'  => $albumId,
        ':album2' => $albumId,
        ':piste'  => $piste,
        ':source' => $estSource ? 1 : 0,
        ':track'  => $trackId,
    ]);
}

/**
 * Complète un album à partir des métadonnées d'un de ses titres.
 *
 * L'année et la pochette ne sont pas connues au développement de la playlist :
 * elles n'apparaissent qu'en interrogeant un titre. On les pose au premier
 * import qui les fournit, sans jamais écraser ce qui est déjà renseigné.
 */
function albumCompleter(PDO $pdo, int $albumId, ?int $annee, ?string $img): void
{
    if (!$annee && !$img) {
        return;
    }

    $req = $pdo->prepare(
        "UPDATE albums
            SET annee = COALESCE(annee, :annee),
                img   = COALESCE(NULLIF(img, ''), :img)
          WHERE id = :id"
    );
    $req->execute([
        ':annee' => $annee ?: null,
        ':img'   => $img ?: null,
        ':id'    => $albumId,
    ]);
}

/**
 * Rend un titre indépendant de l'album qui l'a fait entrer.
 *
 * Un réimport manuel est une prise de possession : l'utilisateur dit « ce
 * titre m'intéresse pour lui-même, pas parce qu'il venait d'un album ». Le
 * lien d'affichage (`album_id`) est conservé — le titre reste visible sur la
 * page de l'album, c'est bien là qu'il a sa place — mais `album_source_id`
 * est effacé, de sorte que la suppression de l'album ne l'emportera plus.
 *
 * Sans effet si le titre n'était pas venu d'un album : la requête ne touche
 * que les lignes dont album_source_id est réellement posé.
 *
 * @return array|null l'album dont il vient d'être détaché, ou null
 */
function albumDetacherTitre(PDO $pdo, int $trackId): ?array
{
    // L'album est lu AVANT la mise à jour : après, l'information a disparu.
    $req = $pdo->prepare(
        "SELECT albums.id, albums.title
           FROM tracks
           JOIN albums ON albums.id = tracks.album_source_id
          WHERE tracks.id = :track"
    );
    $req->execute([':track' => $trackId]);
    $album = $req->fetch(PDO::FETCH_ASSOC);

    if (!$album) {
        return null;
    }

    $req = $pdo->prepare("UPDATE tracks SET album_source_id = NULL WHERE id = :track");
    $req->execute([':track' => $trackId]);

    journalInfo('contenu', 'titre_rendu_autonome',
        'Titre rendu indépendant de son album',
        ['track_id' => $trackId, 'album_id' => (int) $album['id'], 'album' => $album['title']]);

    return $album;
}
