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
 * Les genres se croisent de la même façon et dans la même sélection : rien ne
 * distingue « le rock » d'une étiquette du point de vue du geste, et vouloir
 * « tout le rock calme » est exactement le même besoin.
 *
 * Entrée GET : tags, genres (ids séparés par des virgules), mode
 * Sortie JSON : { success, etiquettes, genres, titres, total }
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
$entiers = static function (?string $brut): array {
    return array_values(array_filter(array_map(
        static fn ($v) => (int) $v,
        explode(',', (string) $brut)
    ), static fn (int $v): bool => $v > 0));
};

$tagsDemandes   = $entiers(filter_input(INPUT_GET, 'tags'));
$genresDemandes = $entiers(filter_input(INPUT_GET, 'genres'));

$mode = filter_input(INPUT_GET, 'mode') === 'ou' ? 'ou' : 'et';

/**
 * Conditions de sélection, en EXISTS.
 *
 * EXISTS plutôt qu'une jointure avec GROUP BY / HAVING : il faut croiser deux
 * tables de liaison différentes (étiquettes et genres), et le compte d'un
 * HAVING ne sait pas dire laquelle des deux a fourni quoi. Avec EXISTS,
 * chaque critère s'exprime seul et se combine sans s'emmêler.
 *
 * Les identifiants sont interpolés : ils viennent d'être réduits à des entiers
 * positifs, c'est cette réduction qui tient lieu de garde.
 */
$conditions = static function (array $ids, string $table, string $colonne, string $mode): array {
    if (!$ids) { return []; }

    $exists = static fn (string $in): string =>
        "EXISTS (SELECT 1 FROM $table WHERE $table.track_id = tracks.id AND $table.$colonne IN ($in))";

    if ($mode === 'ou') {
        return [$exists(implode(',', $ids))];
    }
    // « et » : une condition par identifiant, toutes obligatoires.
    return array_map(static fn (int $id): string => $exists((string) $id), $ids);
};

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

    // Les genres se présentent comme les étiquettes : même forme, même usage.
    $genres = $pdo->query("
        SELECT genres.id, genres.name,
               COUNT(track__genre.track_id) AS nb_titres
          FROM genres
          LEFT JOIN track__genre ON track__genre.genre_id = genres.id
         GROUP BY genres.id, genres.name
         ORDER BY nb_titres DESC, genres.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $titres = [];

    $clauses = array_merge(
        $conditions($tagsDemandes, 'tag__track', 'tag_id', $mode),
        $conditions($genresDemandes, 'track__genre', 'genre_id', $mode)
    );

    if ($clauses) {
        /*
         * En mode « ou », les deux familles restent unies entre elles : cocher
         * le genre Rock et l'étiquette « calme » demande l'un OU l'autre.
         * En mode « et », tout est obligatoire.
         */
        $where = implode($mode === 'ou' ? ' OR ' : ' AND ', $clauses);

        /*
         * HAVING COUNT(DISTINCT …) = n : c'est le croisement.
         *
         * DISTINCT n'est pas décoratif — sans lui, un titre portant deux fois
         * la même étiquette (doublon dans la table de liaison) satisferait le
         * compte sans porter toutes les étiquettes demandées.
         */
        $req = $pdo->query("
            SELECT tracks.id, tracks.title, tracks.img, tracks.duration,
                   GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artists_names
              FROM tracks
              LEFT JOIN artist__track ON artist__track.track_id = tracks.id
              LEFT JOIN artists ON artists.id = artist__track.artist_id
             WHERE $where
             GROUP BY tracks.id, tracks.title, tracks.img, tracks.duration
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
        'genres'      => array_map(static fn (array $g): array => [
            'id'        => (int) $g['id'],
            'name'      => $g['name'],
            'nb_titres' => (int) $g['nb_titres'],
        ], $genres),
        'titres'      => $titres,
        'total'       => count($titres),
        'mode'        => $mode,
    ]);
} catch (Throwable $e) {
    echecJson('etiquettes', $e, 'Impossible de lire les étiquettes');
}
