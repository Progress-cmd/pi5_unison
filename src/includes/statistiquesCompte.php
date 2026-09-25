<?php
/**
 * Statistiques du tableau de bord personnel.
 *
 * Toutes les requêtes sont filtrées sur l'utilisateur courant : ce sont SES
 * écoutes, SES favoris. Les compteurs globaux du dashboard (total des morceaux,
 * des playlists) restent volontairement globaux — c'est l'état de la
 * discothèque commune, pas une statistique personnelle.
 */

/** Les artistes les plus écoutés par ce compte. */
function statTopArtistes(PDO $pdo, int $userId, int $limite = 3): array
{
    // LIMIT n'accepte pas de paramètre lié en préparation native.
    $limite = max(1, min(20, $limite));

    $req = $pdo->prepare("
        SELECT artists.id, artists.name, artists.img, SUM(nb_listen.nb) AS ecoutes
          FROM nb_listen
          JOIN artist__track ON artist__track.track_id = nb_listen.track_id
          JOIN artists       ON artists.id = artist__track.artist_id
         WHERE nb_listen.user_id = :user
         GROUP BY artists.id, artists.name, artists.img
         ORDER BY ecoutes DESC, artists.name
         LIMIT $limite
    ");
    $req->execute([':user' => $userId]);

    return $req->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Écoutes des sept derniers jours, un élément par jour.
 *
 * Les jours sans écoute sont présents avec un compte à zéro : un graphique
 * qui saute les jours vides ment sur le rythme réel.
 *
 * @return array<int, array{jour: string, libelle: string, n: int}>
 */
function statSemaine(PDO $pdo, int $userId): array
{
    $req = $pdo->prepare("
        SELECT DATE(`listened-at`) AS jour, COUNT(*) AS n
          FROM historical
         WHERE `listened-by_id` = :user
           AND `listened-at` >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         GROUP BY DATE(`listened-at`)
    ");
    $req->execute([':user' => $userId]);

    $parJour = [];
    foreach ($req->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $parJour[$l['jour']] = (int) $l['n'];
    }

    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $semaine = [];

    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i day"));
        $semaine[] = [
            'jour'    => $date,
            'libelle' => $jours[(int) date('w', strtotime($date))],
            'n'       => $parJour[$date] ?? 0,
        ];
    }

    return $semaine;
}

/**
 * Répartition des écoutes sur la journée, en quatre moments.
 *
 * Quatre tranches plutôt que vingt-quatre heures : avec peu d'écoutes, un
 * histogramme horaire ne montre que du bruit, alors que « surtout le soir »
 * reste vrai dès les premières dizaines.
 */
function statMoments(PDO $pdo, int $userId): array
{
    $req = $pdo->prepare("
        SELECT CASE
                 WHEN HOUR(`listened-at`) BETWEEN 6  AND 11 THEN 'matin'
                 WHEN HOUR(`listened-at`) BETWEEN 12 AND 17 THEN 'apres_midi'
                 WHEN HOUR(`listened-at`) BETWEEN 18 AND 22 THEN 'soir'
                 ELSE 'nuit'
               END AS moment,
               COUNT(*) AS n
          FROM historical
         WHERE `listened-by_id` = :user
         GROUP BY moment
    ");
    $req->execute([':user' => $userId]);

    $moments = ['matin' => 0, 'apres_midi' => 0, 'soir' => 0, 'nuit' => 0];
    foreach ($req->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $moments[$l['moment']] = (int) $l['n'];
    }

    return $moments;
}

/** Titres entrés dans la discothèque ces trente derniers jours. */
function statDecouvertes(PDO $pdo, int $limite = 5): array
{
    $limite = max(1, min(20, $limite));

    return $pdo->query("
        SELECT tracks.id, tracks.title, tracks.img, tracks.`created-at`,
               GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artists_names
          FROM tracks
          LEFT JOIN artist__track ON artist__track.track_id = tracks.id
          LEFT JOIN artists       ON artists.id = artist__track.artist_id
         WHERE tracks.`created-at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY tracks.id, tracks.title, tracks.img, tracks.`created-at`
         ORDER BY tracks.`created-at` DESC
         LIMIT $limite
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/** Nombres d'albums et d'artistes favoris de ce compte. */
function statCollections(PDO $pdo, int $userId): array
{
    $req = $pdo->prepare("SELECT COUNT(*) FROM artist__favorite WHERE user_id = :user");
    $req->execute([':user' => $userId]);

    return [
        'albums'            => (int) $pdo->query("SELECT COUNT(*) FROM albums")->fetchColumn(),
        'artistes_favoris'  => (int) $req->fetchColumn(),
    ];
}
