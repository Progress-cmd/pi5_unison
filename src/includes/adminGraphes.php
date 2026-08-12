<?php
/**
 * Graphiques du tableau de bord, rendus en SVG côté serveur.
 *
 * Pas de bibliothèque de graphes : le SVG est généré directement, ce qui évite
 * une dépendance externe et respecte la règle du reste de l'application — rien
 * n'est chargé depuis un service tiers.
 *
 * Chaque fonction de données renvoie une série [['libelle' => …, 'valeur' => …]]
 * et chaque fonction de rendu sait afficher un état « pas assez de données »
 * plutôt qu'un graphique vide et trompeur.
 */

require_once __DIR__ . '/config.php';

/** Nombre minimal de points sous lequel un graphique n'apprend rien. */
const GRAPHE_MINIMUM = 2;

// ------------------------------------------------------------------ DONNÉES

/** Écoutes par jour sur les N derniers jours, trous compris. */
function ecoutesParJour(PDO $pdo, int $jours = 30): array
{
    $req = $pdo->prepare("
        SELECT DATE(`listened-at`) AS jour, COUNT(*) AS n
        FROM historical
        WHERE `listened-at` >= DATE_SUB(CURDATE(), INTERVAL :jours DAY)
        GROUP BY DATE(`listened-at`)
    ");
    $req->bindValue(':jours', $jours, PDO::PARAM_INT);
    $req->execute();

    $parJour = array_column($req->fetchAll(PDO::FETCH_ASSOC), 'n', 'jour');

    // Les jours sans écoute doivent apparaître à zéro : sinon la courbe ment
    // en rapprochant deux dates éloignées.
    $serie = [];
    for ($i = $jours - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i day"));
        $serie[] = [
            'libelle' => date('d/m', strtotime($date)),
            'valeur'  => (int) ($parJour[$date] ?? 0),
        ];
    }

    return $serie;
}

/** Répartition des écoutes sur les 24 heures de la journée. */
function ecoutesParHeure(PDO $pdo): array
{
    $parHeure = array_column(
        $pdo->query("SELECT HOUR(`listened-at`) AS h, COUNT(*) AS n FROM historical GROUP BY HOUR(`listened-at`)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'n', 'h'
    );

    $serie = [];
    for ($h = 0; $h < 24; $h++) {
        $serie[] = [
            'libelle' => str_pad((string) $h, 2, '0', STR_PAD_LEFT) . 'h',
            'valeur'  => (int) ($parHeure[$h] ?? 0),
        ];
    }

    return $serie;
}

/** Titres ajoutés par mois, pour voir grandir la bibliothèque. */
function ajoutsParMois(PDO $pdo, int $mois = 12): array
{
    $req = $pdo->prepare("
        SELECT DATE_FORMAT(`created-at`, '%Y-%m') AS mois, COUNT(*) AS n
        FROM tracks
        WHERE `created-at` >= DATE_SUB(CURDATE(), INTERVAL :mois MONTH)
        GROUP BY DATE_FORMAT(`created-at`, '%Y-%m')
    ");
    $req->bindValue(':mois', $mois, PDO::PARAM_INT);
    $req->execute();

    $parMois = array_column($req->fetchAll(PDO::FETCH_ASSOC), 'n', 'mois');

    $serie = [];
    for ($i = $mois - 1; $i >= 0; $i--) {
        $cle = date('Y-m', strtotime("-$i month"));
        $serie[] = [
            'libelle' => date('m/y', strtotime($cle . '-01')),
            'valeur'  => (int) ($parMois[$cle] ?? 0),
        ];
    }

    return $serie;
}

/** Classement, tous comptes confondus. */
function palmares(PDO $pdo, string $quoi, int $limite = 8): array
{
    $requetes = [
        'titres' => "SELECT tracks.title AS libelle, COUNT(*) AS valeur
                     FROM historical JOIN tracks ON tracks.id = historical.track_id
                     GROUP BY tracks.id, tracks.title ORDER BY valeur DESC LIMIT :limite",
        'artistes' => "SELECT artists.name AS libelle, COUNT(*) AS valeur
                       FROM historical
                       JOIN artist__track ON artist__track.track_id = historical.track_id
                       JOIN artists ON artists.id = artist__track.artist_id
                       GROUP BY artists.id, artists.name ORDER BY valeur DESC LIMIT :limite",
        'genres' => "SELECT genres.name AS libelle, COUNT(*) AS valeur
                     FROM track__genre JOIN genres ON genres.id = track__genre.genre_id
                     GROUP BY genres.id, genres.name ORDER BY valeur DESC LIMIT :limite",
        'comptes' => "SELECT users.username AS libelle, COUNT(historical.track_id) AS valeur
                      FROM users LEFT JOIN historical ON historical.`listened-by_id` = users.id
                      WHERE users.role <> 'admin'
                      GROUP BY users.id, users.username ORDER BY valeur DESC LIMIT :limite",
    ];

    if (!isset($requetes[$quoi])) {
        return [];
    }

    $req = $pdo->prepare($requetes[$quoi]);
    $req->bindValue(':limite', $limite, PDO::PARAM_INT);
    $req->execute();

    return array_map(
        fn($l) => ['libelle' => (string) $l['libelle'], 'valeur' => (int) $l['valeur']],
        $req->fetchAll(PDO::FETCH_ASSOC)
    );
}

// ------------------------------------------------------------------- RENDU

/** Message affiché quand une série ne porte pas assez d'information. */
function grapheVide(string $raison): string
{
    return '<div class="graphe-vide"><span class="material-symbols-outlined">query_stats</span>'
         . htmlspecialchars($raison, ENT_QUOTES) . '</div>';
}

/**
 * Histogramme vertical. Convient aux séries régulières et nombreuses
 * (30 jours, 24 heures, 12 mois).
 *
 * @param int $pasEtiquette n'affiche qu'une étiquette sur N, pour rester lisible.
 */
function grapheBarres(array $serie, int $pasEtiquette = 1, string $unite = ''): string
{
    $total = array_sum(array_column($serie, 'valeur'));
    if ($total === 0) {
        return grapheVide('Aucune donnée sur la période');
    }

    $max = max(array_column($serie, 'valeur'));
    $n = count($serie);

    // Repère : largeur fixe en unités SVG, mise à l'échelle par le CSS.
    $largeur = 100;
    $hauteur = 40;
    $pas = $largeur / $n;
    $largeurBarre = max($pas * 0.6, 0.6);

    $barres = '';
    $etiquettes = '';

    foreach ($serie as $i => $point) {
        $h = $max > 0 ? ($point['valeur'] / $max) * ($hauteur - 2) : 0;
        $x = $i * $pas + ($pas - $largeurBarre) / 2;
        $y = $hauteur - $h;

        $titre = htmlspecialchars($point['libelle'] . ' : ' . $point['valeur'] . ' ' . $unite, ENT_QUOTES);
        $barres .= sprintf(
            '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" class="%s"><title>%s</title></rect>',
            $x, $y, $largeurBarre, max($h, 0.15),
            $point['valeur'] > 0 ? 'graphe-barre' : 'graphe-barre vide',
            $titre
        );

        /*
         * Les étiquettes sont rendues en HTML, pas dans le SVG : celui-ci est
         * étiré à la largeur disponible (preserveAspectRatio="none"), ce qui
         * déforme le texte — très visible sur un écran large. Les barres, elles,
         * supportent l'étirement sans dommage.
         */
        $etiquettes .= '<span>' . ($i % $pasEtiquette === 0
            ? htmlspecialchars($point['libelle'], ENT_QUOTES) : '') . '</span>';
    }

    return '<div class="graphe-cadre">'
         . '<svg class="graphe" viewBox="0 0 ' . $largeur . ' ' . $hauteur . '" preserveAspectRatio="none" role="img">'
         . $barres . '</svg>'
         . '<div class="graphe-axe-x" style="--n:' . $n . '">' . $etiquettes . '</div>'
         . '</div>'
         . '<div class="graphe-legende"><span>max ' . $max . '</span><span>total ' . $total . ' ' . htmlspecialchars($unite, ENT_QUOTES) . '</span></div>';
}

/**
 * Barres horizontales avec libellé : convient aux classements, où le nom
 * compte autant que la valeur.
 */
function grapheClassement(array $serie, string $unite = 'écoutes'): string
{
    $serie = array_values(array_filter($serie, fn($p) => $p['valeur'] > 0));

    if (count($serie) < GRAPHE_MINIMUM) {
        return grapheVide('Pas encore assez d\'écoutes pour un classement');
    }

    $max = max(array_column($serie, 'valeur'));
    $lignes = '';

    foreach ($serie as $point) {
        $pourcent = $max > 0 ? ($point['valeur'] / $max) * 100 : 0;
        $lignes .= '<div class="classement-ligne">'
                 . '<span class="classement-nom">' . htmlspecialchars($point['libelle'], ENT_QUOTES) . '</span>'
                 . '<span class="classement-barre"><span style="width:' . round($pourcent, 1) . '%"></span></span>'
                 . '<span class="classement-valeur">' . $point['valeur'] . '</span>'
                 . '</div>';
    }

    return '<div class="classement" title="en ' . htmlspecialchars($unite, ENT_QUOTES) . '">' . $lignes . '</div>';
}
