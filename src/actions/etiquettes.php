<?php
/**
 * Étiquettes disponibles, et les titres qui les portent.
 *
 * Deux usages en un seul appel, parce que la page en a besoin ensemble :
 *   - sans paramètre `tags` : la liste des étiquettes et leur nombre de titres ;
 *   - avec : les titres correspondants, selon le mode de croisement.
 *
 * Croisement (`mode`) :
 *   - « et » (défaut) : le titre porte TOUTES les étiquettes cochées. C'est ce
 *     qui rend le croisement utile — « calme » ET « instrumental » désigne
 *     autre chose que chacune prise à part ;
 *   - « ou » : le titre porte au moins l'une d'elles.
 *
 * Entrée GET : tags (ids séparés par des virgules), mode
 * Sortie JSON : { success, etiquettes: [...], titres: [...], total }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
include_once "../includes/config.php";

header('Content-Type: application/json');

$pdo = Config::getConnection();

/*
 * Les identifiants sont filtrés en entiers avant d'entrer dans la requête.
 *
 * Ils y sont interpolés et non liés : un IN (...) de taille variable ne se
 * prête pas aux paramètres nommés, et les marques d'interrogation se mêlent
 * mal aux autres paramètres de cette requête. Le filtrage ci-dessous est donc
 * ce qui tient lieu de garde — d'où le array_filter sur des entiers stricts.
 */
$demandes = array_values(array_filter(array_map(
    static fn ($v) => (int) $v,
    explode(',', (string) filter_input(INPUT_GET, 'tags'))
), static fn (int $v): bool => $v > 0));

$mode = filter_input(INPUT_GET, 'mode') === 'ou' ? 'ou' : 'et';

try {
    // --- Liste des étiquettes, toujours renvoyée : la page s'en sert pour
    //     dessiner les pastilles et pour dire combien de titres chacune tient.
    $etiquettes = $pdo->query("
        SELECT tags.id, tags.name,
               COUNT(tag__track.track_id) AS nb_titres
          FROM tags
          LEFT JOIN tag__track ON tag__track.tag_id = tags.id
         GROUP BY tags.id, tags.name
         ORDER BY nb_titres DESC, tags.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $titres = [];

    if ($demandes) {
        $in = implode(',', $demandes);

        /*
         * HAVING COUNT(DISTINCT …) = n : c'est le croisement.
         *
         * DISTINCT n'est pas décoratif — sans lui, un titre portant deux fois
         * la même étiquette (doublon dans la table de liaison) satisferait le
         * compte sans porter toutes les étiquettes demandées.
         */
        $having = $mode === 'et'
            ? 'HAVING COUNT(DISTINCT tag__track.tag_id) = ' . count($demandes)
            : '';

        $req = $pdo->query("
            SELECT tracks.id, tracks.title, tracks.img, tracks.duration,
                   GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artists_names
              FROM tracks
              JOIN tag__track ON tag__track.track_id = tracks.id
              LEFT JOIN artist__track ON artist__track.track_id = tracks.id
              LEFT JOIN artists ON artists.id = artist__track.artist_id
             WHERE tag__track.tag_id IN ($in)
             GROUP BY tracks.id, tracks.title, tracks.img, tracks.duration
             $having
             ORDER BY tracks.title ASC
        ");
        $titres = $req->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success'     => true,
        'etiquettes'  => array_map(static fn (array $e): array => [
            'id'        => (int) $e['id'],
            'name'      => $e['name'],
            'nb_titres' => (int) $e['nb_titres'],
        ], $etiquettes),
        'titres'      => $titres,
        'total'       => count($titres),
        'mode'        => $mode,
    ]);
} catch (Throwable $e) {
    echecJson('etiquettes', $e, 'Impossible de lire les étiquettes');
}
