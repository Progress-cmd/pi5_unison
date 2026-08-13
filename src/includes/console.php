<?php
/**
 * Console d'administration — interprétation des commandes.
 *
 * Un terminal pour interroger le projet sans ouvrir de shell dans le
 * conteneur : « titre 42 », « doublons », « sante » répondent en une ligne à
 * des questions qui demandaient jusqu'ici trois jointures écrites à la main.
 *
 * TROIS RÈGLES, qui expliquent toute la suite :
 *
 *  1. TOUT EST EN LECTURE SEULE. Aucune commande n'écrit, ne supprime ni ne
 *     modifie quoi que ce soit. Les opérations destructrices ont leurs pages
 *     dédiées, avec leurs confirmations ; les mélanger à un terminal où l'on
 *     tape vite serait un piège. Une faute de frappe ici ne coûte rien.
 *  2. Les commandes sont NOMMÉES, pas du SQL. Elles encapsulent les jointures
 *     et le schéma — c'est ce qui les rend utilisables sans avoir la structure
 *     de la base en tête. « sql » existe comme échappatoire, mais reste
 *     contrainte à la lecture par trois mécanismes indépendants (voir
 *     consoleSql).
 *  3. Rien n'est concaténé dans du SQL. Les arguments passent tous par des
 *     requêtes préparées, y compris quand ils viennent d'un administrateur.
 *
 * Aucune garde n'est posée dans ce fichier : c'est à l'appelant
 * (actions/admin/console.php) de commencer par exigerAdmin().
 *
 * @see src/pages/admin_console.php pour l'interface
 * @see src/scripts/console.js pour le rendu des blocs de sortie
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/adminOutils.php';
require_once __DIR__ . '/journalRapport.php';

/* -------------------------------------------------------------------------
 * Blocs de sortie
 *
 * Une commande ne renvoie pas du texte mais une liste de blocs typés, que
 * scripts/console.js sait dessiner. Un tableau reste ainsi un tableau — avec
 * ses colonnes alignées — au lieu d'être aplati en chaîne au moment où il est
 * produit.
 * ---------------------------------------------------------------------- */

/** Ligne de texte simple. */
function blocTexte(string $texte): array
{
    return ['type' => 'texte', 'texte' => $texte];
}

/** Intertitre, pour séparer deux sections d'une même réponse. */
function blocTitre(string $texte): array
{
    return ['type' => 'titre', 'texte' => $texte];
}

/** Message d'échec (commande inconnue, argument manquant, requête refusée). */
function blocErreur(string $texte): array
{
    return ['type' => 'erreur', 'texte' => $texte];
}

/** Confirmation, en vert. */
function blocSucces(string $texte): array
{
    return ['type' => 'succes', 'texte' => $texte];
}

/**
 * Liste « clé : valeur », pour décrire un objet unique.
 * @param array<string, scalar|null> $paires
 */
function blocPaires(array $paires): array
{
    $propres = [];
    foreach ($paires as $cle => $valeur) {
        $propres[] = [(string) $cle, consoleValeur($valeur)];
    }

    return ['type' => 'paires', 'paires' => $propres];
}

/**
 * Tableau à colonnes.
 *
 * @param string[] $colonnes en-têtes
 * @param array    $lignes   liste de tableaux associatifs ou indexés
 */
function blocTableau(array $colonnes, array $lignes): array
{
    $corps = [];

    foreach ($lignes as $ligne) {
        $corps[] = array_map('consoleValeur', array_values((array) $ligne));
    }

    return ['type' => 'tableau', 'colonnes' => $colonnes, 'lignes' => $corps];
}

/**
 * Rend une valeur affichable, et surtout bornée en longueur.
 *
 * Une cellule ne doit jamais pouvoir contenir un champ « text » entier : le
 * terminal deviendrait illisible et la réponse JSON, énorme.
 */
function consoleValeur(mixed $valeur): string
{
    if ($valeur === null) {
        return '—';
    }
    if (is_bool($valeur)) {
        return $valeur ? 'oui' : 'non';
    }
    if (is_array($valeur)) {
        $valeur = json_encode($valeur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $texte = (string) $valeur;

    return mb_strlen($texte) > 200 ? mb_substr($texte, 0, 200) . '…' : $texte;
}

/* -------------------------------------------------------------------------
 * Base cible
 * ---------------------------------------------------------------------- */

/**
 * Base sur laquelle porte la session de console : « principale » ou « demo ».
 *
 * Conservée en session : passer d'une base à l'autre à chaque commande serait
 * pénible, et l'invite du terminal rappelle en permanence où l'on se trouve.
 */
function consoleBaseCourante(): string
{
    return ($_SESSION['console_base'] ?? 'principale') === 'demo' ? 'demo' : 'principale';
}

/**
 * Connexion vers la base cible.
 *
 * @throws PDOException si la base de démonstration est demandée sans exister
 */
function consolePdo(): PDO
{
    return consoleBaseCourante() === 'demo'
        ? Config::getConnectionDemo()
        : Config::getConnectionPrincipale();
}

/* -------------------------------------------------------------------------
 * Catalogue des commandes
 * ---------------------------------------------------------------------- */

/**
 * Toutes les commandes, dans l'ordre d'affichage de l'aide.
 *
 * Chaque entrée porte son propre mode d'emploi : c'est cette table, et rien
 * d'autre, qui alimente « aide » et la complétion du terminal. Ajouter une
 * commande ne demande donc de toucher à aucun autre endroit.
 */
function consoleCommandes(): array
{
    return [
        'aide' => [
            'usage'   => 'aide [commande]',
            'resume'  => 'Liste les commandes, ou détaille l\'une d\'elles',
            'executer' => 'cmdAide',
        ],
        'base' => [
            'usage'   => 'base [principale|demo]',
            'resume'  => 'Affiche ou change la base interrogée',
            'executer' => 'cmdBase',
        ],
        'tables' => [
            'usage'   => 'tables',
            'resume'  => 'Toutes les tables, leur volume et leur poids',
            'executer' => 'cmdTables',
        ],
        'decrire' => [
            'usage'   => 'decrire <table>',
            'resume'  => 'Colonnes, types et clés d\'une table',
            'executer' => 'cmdDecrire',
        ],
        'titre' => [
            'usage'   => 'titre <id | texte>',
            'resume'  => 'Fiche complète d\'un morceau : artistes, genres, playlists, écoutes, fichier',
            'executer' => 'cmdTitre',
        ],
        'artiste' => [
            'usage'   => 'artiste <texte>',
            'resume'  => 'Fiche d\'un artiste et ses titres',
            'executer' => 'cmdArtiste',
        ],
        'playlist' => [
            'usage'   => 'playlist [texte]',
            'resume'  => 'Liste les playlists, ou détaille celle qui correspond',
            'executer' => 'cmdPlaylist',
        ],
        'compte' => [
            'usage'   => 'compte [texte]',
            'resume'  => 'Comptes, leur rôle et ce qu\'ils ont produit',
            'executer' => 'cmdCompte',
        ],
        'recents' => [
            'usage'   => 'recents [nombre]',
            'resume'  => 'Derniers titres ajoutés à la bibliothèque',
            'executer' => 'cmdRecents',
        ],
        'top' => [
            'usage'   => 'top [titres|artistes|comptes|genres] [nombre]',
            'resume'  => 'Palmarès des écoutes',
            'executer' => 'cmdTop',
        ],
        'ecoutes' => [
            'usage'   => 'ecoutes [jours]',
            'resume'  => 'Volume d\'écoutes sur une période',
            'executer' => 'cmdEcoutes',
        ],
        'doublons' => [
            'usage'   => 'doublons',
            'resume'  => 'Titres apparaissant plusieurs fois sous le même nom',
            'executer' => 'cmdDoublons',
        ],
        'orphelins' => [
            'usage'   => 'orphelins',
            'resume'  => 'Fichiers audio que plus aucun titre ne référence',
            'executer' => 'cmdOrphelins',
        ],
        'manquants' => [
            'usage'   => 'manquants',
            'resume'  => 'Titres en base dont le fichier audio a disparu',
            'executer' => 'cmdManquants',
        ],
        'journal' => [
            'usage'   => 'journal [niveau|canal] [nombre]',
            'resume'  => 'Derniers événements du journal',
            'executer' => 'cmdJournal',
        ],
        'sante' => [
            'usage'   => 'sante',
            'resume'  => 'État de la base, de la recherche, du disque et de PHP',
            'executer' => 'cmdSante',
        ],
        'meili' => [
            'usage'   => 'meili',
            'resume'  => 'État des index de recherche',
            'executer' => 'cmdMeili',
        ],
        'sql' => [
            'usage'   => 'sql <SELECT …>',
            'resume'  => 'Requête libre, strictement en lecture seule',
            'executer' => 'cmdSql',
        ],
        'version' => [
            'usage'   => 'version',
            'resume'  => 'Version d\'Unison et des services',
            'executer' => 'cmdVersion',
        ],
    ];
}

/**
 * Interprète une ligne saisie et renvoie ses blocs de sortie.
 *
 * @return array{blocs: array, base: string}
 */
function consoleExecuter(string $ligne): array
{
    $ligne = trim($ligne);

    if ($ligne === '') {
        return ['blocs' => [], 'base' => consoleBaseCourante()];
    }

    /*
     * Découpage volontairement minimal : le nom de commande, puis TOUT le
     * reste en un seul morceau. Les arguments sont souvent des titres à
     * espaces (« titre Sunset Lover ») ou des requêtes entières, qu'un
     * découpage par mots obligerait à remettre bout à bout.
     */
    $espace = strpos($ligne, ' ');
    $nom = strtolower($espace === false ? $ligne : substr($ligne, 0, $espace));
    $argument = $espace === false ? '' : trim(substr($ligne, $espace + 1));

    $commandes = consoleCommandes();

    if (!isset($commandes[$nom])) {
        $blocs = [blocErreur("Commande inconnue : « $nom »")];

        // Suggestion sur la distance d'édition : « titres » ne doit pas
        // renvoyer une aide complète alors que « titre » existe.
        $proche = consoleSuggestion($nom, array_keys($commandes));
        if ($proche !== null) {
            $blocs[] = blocTexte("Vouliez-vous dire « $proche » ?");
        }
        $blocs[] = blocTexte('Tapez « aide » pour la liste des commandes.');

        return ['blocs' => $blocs, 'base' => consoleBaseCourante()];
    }

    try {
        $blocs = $commandes[$nom]['executer']($argument);
    } catch (PDOException $e) {
        /*
         * Le message de MariaDB est affiché tel quel : la console s'adresse à
         * l'administrateur, et « Unknown column 'titel' » est infiniment plus
         * utile qu'une erreur générique. Rien ici n'est montré à un visiteur.
         */
        $blocs = [blocErreur('Erreur SQL : ' . $e->getMessage())];
    } catch (\Throwable $e) {
        $blocs = [blocErreur('Erreur : ' . $e->getMessage())];
        error_log('console (' . $nom . ') : ' . $e->getMessage());
    }

    return ['blocs' => $blocs, 'base' => consoleBaseCourante()];
}

/**
 * Commande la plus proche d'une saisie erronée, ou null si aucune ne l'est
 * suffisamment. Le seuil est volontairement serré : une suggestion à côté de
 * la plaque est pire que pas de suggestion.
 */
function consoleSuggestion(string $saisie, array $connues): ?string
{
    $meilleure = null;
    $meilleurEcart = PHP_INT_MAX;

    foreach ($connues as $candidate) {
        $ecart = levenshtein($saisie, $candidate);
        if ($ecart < $meilleurEcart) {
            $meilleurEcart = $ecart;
            $meilleure = $candidate;
        }
    }

    return $meilleurEcart <= 3 ? $meilleure : null;
}

/* -------------------------------------------------------------------------
 * Commandes — généralités
 * ---------------------------------------------------------------------- */

function cmdAide(string $argument): array
{
    $commandes = consoleCommandes();

    // Aide ciblée sur une commande.
    if ($argument !== '') {
        $nom = strtolower(trim($argument));

        if (!isset($commandes[$nom])) {
            return [blocErreur("Commande inconnue : « $nom »")];
        }

        return [
            blocTitre($commandes[$nom]['usage']),
            blocTexte($commandes[$nom]['resume']),
        ];
    }

    $lignes = [];
    foreach ($commandes as $nom => $infos) {
        $lignes[] = [$infos['usage'], $infos['resume']];
    }

    return [
        blocTitre('Commandes disponibles'),
        blocTableau(['Commande', 'Effet'], $lignes),
        blocTexte(''),
        blocTexte('Toutes les commandes sont en lecture seule : aucune ne modifie la base.'),
        blocTexte('↑ et ↓ rappellent les commandes précédentes, Tab complète, « effacer » vide l\'écran.'),
    ];
}

function cmdBase(string $argument): array
{
    $demande = strtolower(trim($argument));

    if ($demande === '') {
        return [
            blocTexte('Base interrogée : ' . consoleBaseCourante()),
            blocTexte('« base demo » pour passer sur la base de démonstration, '
                    . '« base principale » pour revenir.'),
        ];
    }

    if (!in_array($demande, ['principale', 'demo'], true)) {
        return [blocErreur('Base inconnue. Valeurs possibles : principale, demo')];
    }

    if ($demande === 'demo') {
        // Vérifiée tout de suite : la base de démonstration est facultative,
        // et l'échec doit se produire ici, pas à la commande suivante.
        try {
            Config::getConnectionDemo();
        } catch (PDOException $e) {
            return [
                blocErreur("La base de démonstration est injoignable : " . $e->getMessage()),
                blocTexte("Elle n'est présente que si la démonstration a été installée."),
            ];
        }
    }

    $_SESSION['console_base'] = $demande;

    return [blocSucces('Base interrogée : ' . $demande)];
}

function cmdVersion(string $argument): array
{
    $paires = [
        'Unison'    => UNISON_VERSION,
        'PHP'       => PHP_VERSION,
        'Base'      => consoleBaseCourante(),
    ];

    try {
        $pdo = consolePdo();
        $paires['MariaDB'] = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $paires['Schéma']  = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    } catch (PDOException $e) {
        $paires['MariaDB'] = 'injoignable';
    }

    $meili = clientMeili();
    if ($meili) {
        try {
            $paires['MeiliSearch'] = $meili->version()['pkgVersion'] ?? '?';
        } catch (\Exception $e) {
            $paires['MeiliSearch'] = 'injoignable';
        }
    } else {
        $paires['MeiliSearch'] = 'client indisponible';
    }

    return [blocPaires($paires)];
}

/* -------------------------------------------------------------------------
 * Commandes — structure de la base
 * ---------------------------------------------------------------------- */

function cmdTables(string $argument): array
{
    $pdo = consolePdo();

    /*
     * information_schema donne le poids et une estimation du nombre de lignes.
     * L'estimation d'InnoDB peut être franchement fausse sur les petites
     * tables : on recompte exactement, le catalogue est assez petit pour que
     * ce soit instantané, et un chiffre faux dans un outil de diagnostic est
     * pire que pas de chiffre.
     */
    $tables = $pdo->query(
        "SELECT table_name AS nom, data_length + index_length AS octets
           FROM information_schema.TABLES
          WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
          ORDER BY table_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$tables) {
        return [blocErreur('Aucune table dans cette base.')];
    }

    $lignes = [];
    $totalOctets = 0;

    foreach ($tables as $table) {
        $nom = $table['nom'];

        // Le nom vient d'information_schema, pas du client : il ne peut pas
        // être arbitraire. Les accents inverses le protègent malgré tout.
        $n = (int) $pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '', $nom) . "`")->fetchColumn();

        $totalOctets += (int) $table['octets'];
        $lignes[] = [$nom, $n, formaterOctets((int) $table['octets'])];
    }

    return [
        blocTableau(['Table', 'Lignes', 'Poids'], $lignes),
        blocTexte(count($lignes) . ' table(s), ' . formaterOctets($totalOctets) . ' au total.'),
    ];
}

function cmdDecrire(string $argument): array
{
    $table = trim($argument);

    if ($table === '') {
        return [blocErreur('Usage : decrire <table>')];
    }

    $pdo = consolePdo();

    /*
     * Le nom de table ne peut pas être un paramètre lié dans un DESCRIBE : il
     * est donc vérifié contre information_schema AVANT d'être interpolé. Seul
     * un nom qui existe réellement dans la base courante passe, ce qui ferme
     * la question de l'injection sans avoir à filtrer des caractères.
     */
    $req = $pdo->prepare(
        "SELECT table_name FROM information_schema.TABLES
          WHERE table_schema = DATABASE() AND table_name = :nom"
    );
    $req->execute([':nom' => $table]);
    $reel = $req->fetchColumn();

    if ($reel === false) {
        return [
            blocErreur("Table inconnue : « $table »"),
            blocTexte('« tables » donne la liste.'),
        ];
    }

    $colonnes = $pdo->query("DESCRIBE `$reel`")->fetchAll(PDO::FETCH_ASSOC);

    $lignes = [];
    foreach ($colonnes as $c) {
        $lignes[] = [
            $c['Field'],
            $c['Type'],
            $c['Null'] === 'YES' ? 'oui' : 'non',
            $c['Key'] ?: '—',
            $c['Default'] ?? '—',
        ];
    }

    $blocs = [
        blocTitre('Table ' . $reel),
        blocTableau(['Colonne', 'Type', 'Nullable', 'Clé', 'Défaut'], $lignes),
    ];

    // Les clés étrangères ne sont pas dans DESCRIBE, et ce sont elles qui
    // expliquent ce qu'une suppression va entraîner.
    $req = $pdo->prepare(
        "SELECT column_name AS colonne, referenced_table_name AS vers,
                referenced_column_name AS colonne_cible
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE table_schema = DATABASE() AND table_name = :nom
            AND referenced_table_name IS NOT NULL"
    );
    $req->execute([':nom' => $reel]);
    $liens = $req->fetchAll(PDO::FETCH_ASSOC);

    if ($liens) {
        $blocs[] = blocTitre('Clés étrangères');
        $blocs[] = blocTableau(['Colonne', 'Référence', 'Colonne cible'], $liens);
    }

    return $blocs;
}

/* -------------------------------------------------------------------------
 * Commandes — contenu
 * ---------------------------------------------------------------------- */

/**
 * Fiche complète d'un titre.
 *
 * Accepte un identifiant ou un fragment de nom : en pratique on connaît
 * rarement l'identifiant, et l'imposer obligerait à une recherche préalable à
 * chaque fois.
 */
function cmdTitre(string $argument): array
{
    $argument = trim($argument);

    if ($argument === '') {
        return [blocErreur('Usage : titre <id | texte>')];
    }

    $pdo = consolePdo();

    if (ctype_digit($argument)) {
        $req = $pdo->prepare("SELECT * FROM tracks WHERE id = :id");
        $req->execute([':id' => (int) $argument]);
        $titres = $req->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $req = $pdo->prepare("SELECT * FROM tracks WHERE title LIKE :motif ORDER BY title LIMIT 20");
        $req->execute([':motif' => '%' . consoleEchapperLike($argument) . '%']);
        $titres = $req->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!$titres) {
        return [blocTexte('Aucun titre ne correspond à « ' . $argument . ' ».')];
    }

    // Plusieurs correspondances : on liste, sans détailler chacune.
    if (count($titres) > 1) {
        $lignes = array_map(
            fn($t) => [$t['id'], $t['title'], formaterDuree((int) $t['duration'])],
            $titres
        );

        return [
            blocTexte(count($titres) . ' titres correspondent — précisez, ou reprenez un identifiant :'),
            blocTableau(['id', 'Titre', 'Durée'], $lignes),
        ];
    }

    $titre = $titres[0];
    $id = (int) $titre['id'];

    // --- Rattachements ---
    $artistes = $pdo->prepare(
        "SELECT artists.name FROM artist__track
           JOIN artists ON artists.id = artist__track.artist_id
          WHERE artist__track.track_id = :id ORDER BY artists.name"
    );
    $artistes->execute([':id' => $id]);

    $genres = $pdo->prepare(
        "SELECT genres.name FROM track__genre
           JOIN genres ON genres.id = track__genre.genre_id
          WHERE track__genre.track_id = :id ORDER BY genres.name"
    );
    $genres->execute([':id' => $id]);

    $etiquettes = $pdo->prepare(
        "SELECT tags.name FROM tag__track
           JOIN tags ON tags.id = tag__track.tag_id
          WHERE tag__track.track_id = :id ORDER BY tags.name"
    );
    $etiquettes->execute([':id' => $id]);

    $ecoutes = $pdo->prepare("SELECT COUNT(*) FROM historical WHERE track_id = :id");
    $ecoutes->execute([':id' => $id]);

    $derniere = $pdo->prepare("SELECT MAX(`listened-at`) FROM historical WHERE track_id = :id");
    $derniere->execute([':id' => $id]);

    $ajoutePar = $pdo->prepare("SELECT username FROM users WHERE id = :id");
    $ajoutePar->execute([':id' => (int) $titre['added-by_id']]);

    // --- Fichier sur le disque ---
    $chemin = Config::cheminMusiques() . $titre['file'];
    $existe = $titre['file'] !== '' && is_file($chemin);

    $blocs = [
        blocTitre('Titre #' . $id . ' — ' . $titre['title']),
        blocPaires([
            'Artistes'      => implode(', ', $artistes->fetchAll(PDO::FETCH_COLUMN)) ?: '—',
            'Durée'         => formaterDuree((int) $titre['duration']),
            'Genres'        => implode(', ', $genres->fetchAll(PDO::FETCH_COLUMN)) ?: '—',
            'Étiquettes'    => implode(', ', $etiquettes->fetchAll(PDO::FETCH_COLUMN)) ?: '—',
            'Ajouté par'    => $ajoutePar->fetchColumn() ?: 'compte supprimé',
            'Ajouté le'     => $titre['created-at'],
            'Écoutes'       => (int) $ecoutes->fetchColumn(),
            'Dernière écoute' => $derniere->fetchColumn() ?: 'jamais',
            'Fichier'       => $titre['file'] ?: '—',
            'Sur le disque' => $existe
                ? formaterOctets((int) @filesize($chemin))
                : 'ABSENT — le titre est visible mais illisible',
            'Source'        => $titre['url'] ?: '—',
        ]),
    ];

    // Playlists : utile pour savoir ce qu'une suppression va vider.
    $playlists = $pdo->prepare(
        "SELECT playlists.name AS playlist, users.username AS proprietaire
           FROM track__playlist
           JOIN playlists ON playlists.id = track__playlist.playlist_id
           JOIN users ON users.id = playlists.`created-by_id`
          WHERE track__playlist.track_id = :id
          ORDER BY playlists.name"
    );
    $playlists->execute([':id' => $id]);
    $dansPlaylists = $playlists->fetchAll(PDO::FETCH_ASSOC);

    if ($dansPlaylists) {
        $blocs[] = blocTitre('Présent dans ' . count($dansPlaylists) . ' playlist(s)');
        $blocs[] = blocTableau(['Playlist', 'Propriétaire'], $dansPlaylists);
    }

    return $blocs;
}

function cmdArtiste(string $argument): array
{
    $argument = trim($argument);

    if ($argument === '') {
        return [blocErreur('Usage : artiste <texte>')];
    }

    $pdo = consolePdo();

    $req = $pdo->prepare("SELECT * FROM artists WHERE name LIKE :motif ORDER BY name LIMIT 20");
    $req->execute([':motif' => '%' . consoleEchapperLike($argument) . '%']);
    $artistes = $req->fetchAll(PDO::FETCH_ASSOC);

    if (!$artistes) {
        return [blocTexte('Aucun artiste ne correspond à « ' . $argument . ' ».')];
    }

    if (count($artistes) > 1) {
        return [
            blocTexte(count($artistes) . ' artistes correspondent :'),
            blocTableau(['id', 'Nom'], array_map(fn($a) => [$a['id'], $a['name']], $artistes)),
        ];
    }

    $artiste = $artistes[0];
    $id = (int) $artiste['id'];

    $titres = $pdo->prepare(
        "SELECT tracks.id, tracks.title AS titre, tracks.duration AS duree,
                (SELECT COUNT(*) FROM historical WHERE historical.track_id = tracks.id) AS ecoutes
           FROM artist__track
           JOIN tracks ON tracks.id = artist__track.track_id
          WHERE artist__track.artist_id = :id
          ORDER BY ecoutes DESC, tracks.title"
    );
    $titres->execute([':id' => $id]);
    $sesTitres = $titres->fetchAll(PDO::FETCH_ASSOC);

    $genres = $pdo->prepare(
        "SELECT genres.name FROM artist__genre
           JOIN genres ON genres.id = artist__genre.genre_id
          WHERE artist__genre.artist_id = :id ORDER BY genres.name"
    );
    $genres->execute([':id' => $id]);

    $blocs = [
        blocTitre('Artiste #' . $id . ' — ' . $artiste['name']),
        blocPaires([
            'Genres'  => implode(', ', $genres->fetchAll(PDO::FETCH_COLUMN)) ?: '—',
            'Titres'  => count($sesTitres),
            'Écoutes' => array_sum(array_column($sesTitres, 'ecoutes')),
            'Image'   => $artiste['img'] ?: 'aucune',
        ]),
    ];

    if ($sesTitres) {
        $blocs[] = blocTableau(
            ['id', 'Titre', 'Durée', 'Écoutes'],
            array_map(
                fn($t) => [$t['id'], $t['titre'], formaterDuree((int) $t['duree']), $t['ecoutes']],
                $sesTitres
            )
        );
    }

    return $blocs;
}

function cmdPlaylist(string $argument): array
{
    $pdo = consolePdo();
    $argument = trim($argument);

    // Sans argument : la liste de toutes les playlists, système comprises.
    if ($argument === '') {
        $req = $pdo->query(
            "SELECT playlists.id, playlists.name AS nom, users.username AS proprietaire,
                    (SELECT COUNT(*) FROM track__playlist
                      WHERE track__playlist.playlist_id = playlists.id) AS titres,
                    playlists.`updated-at` AS modifiee
               FROM playlists
               JOIN users ON users.id = playlists.`created-by_id`
              ORDER BY playlists.`updated-at` DESC"
        );

        $lignes = $req->fetchAll(PDO::FETCH_ASSOC);

        return [
            blocTitre(count($lignes) . ' playlist(s)'),
            blocTableau(['id', 'Nom', 'Propriétaire', 'Titres', 'Modifiée'], $lignes),
        ];
    }

    $req = $pdo->prepare(
        "SELECT playlists.*, users.username AS proprietaire
           FROM playlists
           JOIN users ON users.id = playlists.`created-by_id`
          WHERE playlists.name LIKE :motif
          ORDER BY playlists.name LIMIT 20"
    );
    $req->execute([':motif' => '%' . consoleEchapperLike($argument) . '%']);
    $playlists = $req->fetchAll(PDO::FETCH_ASSOC);

    if (!$playlists) {
        return [blocTexte('Aucune playlist ne correspond à « ' . $argument . ' ».')];
    }

    if (count($playlists) > 1) {
        return [
            blocTexte(count($playlists) . ' playlists correspondent :'),
            blocTableau(
                ['id', 'Nom', 'Propriétaire'],
                array_map(fn($p) => [$p['id'], $p['name'], $p['proprietaire']], $playlists)
            ),
        ];
    }

    $playlist = $playlists[0];

    $titres = $pdo->prepare(
        "SELECT track__playlist.position, tracks.id, tracks.title AS titre,
                tracks.duration AS duree
           FROM track__playlist
           JOIN tracks ON tracks.id = track__playlist.track_id
          WHERE track__playlist.playlist_id = :id
          ORDER BY track__playlist.position"
    );
    $titres->execute([':id' => (int) $playlist['id']]);
    $sesTitres = $titres->fetchAll(PDO::FETCH_ASSOC);

    $blocs = [
        blocTitre('Playlist #' . $playlist['id'] . ' — ' . $playlist['name']),
        blocPaires([
            'Propriétaire' => $playlist['proprietaire'],
            'Créée le'     => $playlist['created-at'],
            'Modifiée le'  => $playlist['updated-at'],
            'Titres'       => count($sesTitres),
            'Durée totale' => formaterDuree((int) array_sum(array_column($sesTitres, 'duree'))),
        ]),
    ];

    if ($sesTitres) {
        $blocs[] = blocTableau(
            ['Pos.', 'id', 'Titre', 'Durée'],
            array_map(
                fn($t) => [$t['position'], $t['id'], $t['titre'], formaterDuree((int) $t['duree'])],
                $sesTitres
            )
        );
    }

    return $blocs;
}

function cmdCompte(string $argument): array
{
    $pdo = consolePdo();
    $comptes = listerComptes($pdo);

    $argument = trim($argument);
    if ($argument !== '') {
        $recherche = mb_strtolower($argument);
        $comptes = array_values(array_filter(
            $comptes,
            fn($c) => str_contains(mb_strtolower($c['username']), $recherche)
        ));

        if (!$comptes) {
            return [blocTexte('Aucun compte ne correspond à « ' . $argument . ' ».')];
        }
    }

    $lignes = array_map(fn($c) => [
        $c['id'],
        $c['username'],
        $c['role'],
        $c['nb_titres'],
        $c['nb_playlists'],
        $c['nb_ecoutes'],
        formaterDuree((int) $c['temps']),
    ], $comptes);

    return [blocTableau(
        ['id', 'Compte', 'Rôle', 'Titres', 'Playlists', 'Écoutes', 'Temps écouté'],
        $lignes
    )];
}

function cmdRecents(string $argument): array
{
    $pdo = consolePdo();
    $limite = consoleNombre($argument, 15, 100);

    // LIMIT interpolé : entier borné par consoleNombre(), jamais une chaîne
    // du client. MariaDB refuse un paramètre lié à cet endroit en requête
    // préparée native.
    $req = $pdo->query(
        "SELECT tracks.id, tracks.title AS titre,
                (SELECT GROUP_CONCAT(artists.name SEPARATOR ', ')
                   FROM artist__track
                   JOIN artists ON artists.id = artist__track.artist_id
                  WHERE artist__track.track_id = tracks.id) AS artistes,
                users.username AS ajoute_par, tracks.`created-at` AS ajoute_le
           FROM tracks
           JOIN users ON users.id = tracks.`added-by_id`
          ORDER BY tracks.`created-at` DESC, tracks.id DESC
          LIMIT $limite"
    );

    $lignes = $req->fetchAll(PDO::FETCH_ASSOC);

    if (!$lignes) {
        return [blocTexte('La bibliothèque est vide.')];
    }

    return [blocTableau(['id', 'Titre', 'Artistes', 'Ajouté par', 'Ajouté le'], $lignes)];
}

function cmdTop(string $argument): array
{
    $morceaux = preg_split('/\s+/', trim($argument), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $quoi = $morceaux[0] ?? 'titres';
    $limite = consoleNombre($morceaux[1] ?? '', 10, 50);

    $connus = ['titres', 'artistes', 'comptes', 'genres'];
    if (!in_array($quoi, $connus, true)) {
        return [blocErreur('Palmarès inconnu. Valeurs possibles : ' . implode(', ', $connus))];
    }

    $serie = palmares(consolePdo(), $quoi, $limite);

    if (!$serie) {
        return [blocTexte('Aucune écoute enregistrée pour ce palmarès.')];
    }

    $unite = $quoi === 'genres' ? 'Titres' : 'Écoutes';

    return [
        blocTitre('Palmarès : ' . $quoi),
        blocTableau(['Nom', $unite], array_map(fn($p) => [$p['libelle'], $p['valeur']], $serie)),
    ];
}

function cmdEcoutes(string $argument): array
{
    $pdo = consolePdo();
    $jours = consoleNombre($argument, 30, 365);

    // $jours est borné par consoleNombre() : l'interpoler est sans risque, et
    // l'opérande d'un INTERVAL n'accepte pas partout un paramètre lié en
    // requête préparée native.
    $req = $pdo->query(
        "SELECT COUNT(*) AS total,
                COUNT(DISTINCT track_id) AS titres,
                COUNT(DISTINCT `listened-by_id`) AS comptes,
                MIN(`listened-at`) AS premiere,
                MAX(`listened-at`) AS derniere
           FROM historical
          WHERE `listened-at` >= CURDATE() - INTERVAL $jours DAY"
    );
    $resume = $req->fetch(PDO::FETCH_ASSOC);

    $blocs = [
        blocTitre("Écoutes sur $jours jour(s)"),
        blocPaires([
            'Écoutes'          => (int) $resume['total'],
            'Titres distincts' => (int) $resume['titres'],
            'Comptes actifs'   => (int) $resume['comptes'],
            'Première'         => $resume['premiere'] ?: '—',
            'Dernière'         => $resume['derniere'] ?: '—',
            'Moyenne par jour' => $jours > 0 ? round((int) $resume['total'] / $jours, 1) : 0,
        ]),
    ];

    // Répartition par compte : c'est la question qu'on se pose vraiment quand
    // deux personnes partagent une bibliothèque.
    $parCompte = $pdo->query(
        "SELECT users.username AS compte, COUNT(*) AS ecoutes
           FROM historical
           JOIN users ON users.id = historical.`listened-by_id`
          WHERE historical.`listened-at` >= CURDATE() - INTERVAL $jours DAY
          GROUP BY users.id, users.username
          ORDER BY ecoutes DESC"
    );
    $lignes = $parCompte->fetchAll(PDO::FETCH_ASSOC);

    if ($lignes) {
        $blocs[] = blocTableau(['Compte', 'Écoutes'], $lignes);
    }

    return $blocs;
}

function cmdDoublons(string $argument): array
{
    $pdo = consolePdo();

    /*
     * Deux lignes portant le même titre ne sont pas forcément un doublon (une
     * reprise, un live), d'où l'affichage des identifiants et des artistes :
     * la décision revient à l'administrateur, la commande ne fait que signaler.
     */
    $req = $pdo->query(
        "SELECT tracks.title AS titre, COUNT(*) AS occurrences,
                GROUP_CONCAT(tracks.id ORDER BY tracks.id SEPARATOR ', ') AS identifiants
           FROM tracks
          GROUP BY tracks.title
         HAVING occurrences > 1
          ORDER BY occurrences DESC, tracks.title"
    );

    $lignes = $req->fetchAll(PDO::FETCH_ASSOC);

    if (!$lignes) {
        return [blocSucces('Aucun titre en double.')];
    }

    return [
        blocTitre(count($lignes) . ' titre(s) apparaissant plusieurs fois'),
        blocTableau(['Titre', 'Occurrences', 'Identifiants'], $lignes),
        blocTexte('Un même nom peut être légitime (reprise, version live) : '
                . 'vérifiez avec « titre <id> » avant toute suppression.'),
    ];
}

/* -------------------------------------------------------------------------
 * Commandes — stockage et exploitation
 * ---------------------------------------------------------------------- */

function cmdOrphelins(string $argument): array
{
    $analyse = analyserStockage(consolePdo());
    $orphelins = $analyse['orphelins'];

    if (!$orphelins) {
        return [blocSucces('Aucun fichier orphelin. ' . formaterOctets($analyse['octets_total'])
                         . ' occupés par le catalogue.')];
    }

    return [
        blocTitre(count($orphelins) . ' fichier(s) orphelin(s) — '
                . formaterOctets($analyse['octets_orphelins']) . ' récupérables'),
        blocTableau(
            ['Fichier', 'Taille'],
            array_map(fn($o) => [$o['fichier'], formaterOctets($o['octets'])], $orphelins)
        ),
        blocTexte('La suppression se fait depuis la page Stockage : la console ne modifie rien.'),
    ];
}

function cmdManquants(string $argument): array
{
    $analyse = analyserStockage(consolePdo());
    $manquants = $analyse['manquants'];

    if (!$manquants) {
        return [blocSucces('Tous les titres ont leur fichier audio.')];
    }

    return [
        blocTitre(count($manquants) . ' titre(s) sans fichier audio'),
        blocTableau(
            ['id', 'Titre', 'Fichier attendu'],
            array_map(fn($t) => [$t['id'], $t['title'], $t['file'] ?: '(vide)'], $manquants)
        ),
        blocTexte('Ces titres sont visibles dans l\'interface mais ne se lisent pas.'),
    ];
}

function cmdJournal(string $argument): array
{
    // Le journal ne vit que dans la base principale, quelle que soit la base
    // sélectionnée : le préciser évite de croire qu'il est vide côté démo.
    $pdo = Config::getConnectionPrincipale();

    if (!journalTableExiste($pdo)) {
        return [
            blocErreur("La table du journal n'existe pas."),
            blocTexte('Appliquez mysql_init/migrations/002_journal.sql.'),
        ];
    }

    $morceaux = preg_split('/\s+/', trim($argument), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $filtres = [];
    $limite = 20;

    foreach ($morceaux as $morceau) {
        if (ctype_digit($morceau)) {
            $limite = consoleNombre($morceau, 20, 200);
        } elseif (in_array($morceau, JOURNAL_NIVEAUX, true)) {
            $filtres['niveau'] = $morceau;
        } elseif (isset(JOURNAL_CANAUX[$morceau])) {
            $filtres['canal'] = $morceau;
        } else {
            return [blocErreur("« $morceau » n'est ni un niveau ni un canal.\n"
                             . 'Niveaux : ' . implode(', ', JOURNAL_NIVEAUX) . "\n"
                             . 'Canaux : ' . implode(', ', array_keys(JOURNAL_CANAUX)))];
        }
    }

    /*
     * journalLister() pagine par JOURNAL_PAR_PAGE ; la console veut un nombre
     * de lignes choisi. On lit la première page et on tronque : la limite
     * demandée est plafonnée à 200, la page en vaut 60, donc plusieurs pages
     * peuvent être nécessaires.
     */
    $evenements = [];
    $page = 1;

    while (count($evenements) < $limite) {
        $lot = journalLister($pdo, $filtres, $page);
        if (!$lot) {
            break;
        }
        $evenements = array_merge($evenements, $lot);
        $page++;
    }

    $evenements = array_slice($evenements, 0, $limite);

    if (!$evenements) {
        return [blocTexte('Aucun événement ne correspond.')];
    }

    $lignes = array_map(fn($e) => [
        journalQuand($e['horodatage']),
        $e['niveau'],
        $e['canal'],
        $e['message'] ?: $e['action'],
        $e['utilisateur'] ?: '—',
    ], $evenements);

    return [
        blocTitre(count($lignes) . ' dernier(s) événement(s)'
                . ($filtres ? ' (' . implode(', ', $filtres) . ')' : '')),
        blocTableau(['Quand', 'Niveau', 'Canal', 'Événement', 'Compte'], $lignes),
    ];
}

function cmdSante(string $argument): array
{
    $blocs = [];

    // --- Base de données ---
    $baseOk = true;
    $paires = [];

    try {
        $pdo = consolePdo();
        $debut = hrtime(true);
        $pdo->query('SELECT 1')->fetchColumn();
        $paires['Base'] = 'OK (' . round((hrtime(true) - $debut) / 1e6, 1) . ' ms)';
        $paires['Schéma'] = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        $poids = $pdo->query(
            "SELECT COALESCE(SUM(data_length + index_length), 0)
               FROM information_schema.TABLES WHERE table_schema = DATABASE()"
        )->fetchColumn();
        $paires['Poids de la base'] = formaterOctets((int) $poids);
    } catch (PDOException $e) {
        $baseOk = false;
        $paires['Base'] = 'INJOIGNABLE — ' . $e->getMessage();
    }

    // --- Recherche ---
    $meili = clientMeili();
    if (!$meili) {
        $paires['Recherche'] = 'client indisponible (vendor/ non chargé)';
    } else {
        try {
            $sante = $meili->health();
            $paires['Recherche'] = ($sante['status'] ?? '?') === 'available'
                ? 'OK'
                : 'état : ' . ($sante['status'] ?? '?');
        } catch (\Exception $e) {
            $paires['Recherche'] = 'INJOIGNABLE — ' . $e->getMessage();
        }
    }

    // --- Disque ---
    $dossier = Config::cheminMusiques();
    $libre = @disk_free_space($dossier);
    $total = @disk_total_space($dossier);

    if ($libre !== false && $total !== false && $total > 0) {
        $pourcent = round(($total - $libre) / $total * 100, 1);
        $paires['Disque'] = formaterOctets((int) $libre) . ' libres sur '
                          . formaterOctets((int) $total) . " ($pourcent % utilisés)";
    } else {
        $paires['Disque'] = 'mesure impossible depuis le conteneur';
    }

    // --- PHP ---
    $paires['PHP'] = PHP_VERSION;
    $paires['Mémoire limite'] = ini_get('memory_limit');
    $paires['Mémoire utilisée'] = formaterOctets(memory_get_peak_usage(true));
    $paires['Unison'] = UNISON_VERSION;

    $blocs[] = blocTitre('État des services');
    $blocs[] = blocPaires($paires);

    // --- Anomalies de contenu ---
    if ($baseOk) {
        try {
            $analyse = analyserStockage(consolePdo());
            $nbOrphelins = count($analyse['orphelins']);
            $nbManquants = count($analyse['manquants']);

            if ($nbOrphelins || $nbManquants) {
                $blocs[] = blocTitre('À traiter');
                $blocs[] = blocPaires([
                    'Fichiers orphelins' => $nbOrphelins . ' (' . formaterOctets($analyse['octets_orphelins']) . ')',
                    'Titres sans fichier' => $nbManquants,
                ]);
            } else {
                $blocs[] = blocSucces('Stockage cohérent : aucun orphelin, aucun titre sans fichier.');
            }
        } catch (\Throwable $e) {
            $blocs[] = blocErreur('Analyse du stockage impossible : ' . $e->getMessage());
        }

        // --- Incidents récents ---
        $principale = Config::getConnectionPrincipale();
        if (journalTableExiste($principale)) {
            $stats = journalStatistiques($principale);
            if ($stats['incidents_24h'] > 0) {
                $blocs[] = blocErreur($stats['incidents_24h']
                    . ' incident(s) dans les dernières 24 h — « journal erreur » pour les voir.');
            } else {
                $blocs[] = blocSucces('Aucun incident journalisé sur les dernières 24 h.');
            }
        }
    }

    return $blocs;
}

function cmdMeili(string $argument): array
{
    $meili = clientMeili();

    if (!$meili) {
        return [blocErreur('Client MeiliSearch indisponible.')];
    }

    try {
        $stats = $meili->stats();
    } catch (\Exception $e) {
        return [blocErreur('MeiliSearch injoignable : ' . $e->getMessage())];
    }

    $lignes = [];
    foreach (($stats['indexes'] ?? []) as $nom => $infos) {
        $lignes[] = [
            $nom,
            $infos['numberOfDocuments'] ?? 0,
            ($infos['isIndexing'] ?? false) ? 'en cours' : 'à jour',
        ];
    }

    $blocs = [
        blocPaires([
            'Taille de la base' => formaterOctets((int) ($stats['databaseSize'] ?? 0)),
            'Dernière mise à jour' => $stats['lastUpdate'] ?? '—',
        ]),
    ];

    if ($lignes) {
        $blocs[] = blocTableau(['Index', 'Documents', 'État'], $lignes);
    } else {
        $blocs[] = blocTexte('Aucun index. Reconstruisez-les depuis la page Maintenance.');
    }

    return $blocs;
}

/* -------------------------------------------------------------------------
 * Requête libre
 * ---------------------------------------------------------------------- */

/** Nombre maximal de lignes rapportées par « sql ». */
const CONSOLE_SQL_LIGNES_MAX = 200;

/** Temps maximal accordé à une requête libre, en secondes. */
const CONSOLE_SQL_SECONDES_MAX = 5;

/**
 * Exécute une requête SQL fournie par l'administrateur, en lecture seule.
 *
 * L'échappatoire nécessaire : les commandes nommées couvrent les questions
 * fréquentes, jamais toutes. Trois mécanismes INDÉPENDANTS garantissent qu'une
 * requête ne peut rien modifier — chacun suffirait presque seul, et c'est
 * voulu : le filtrage syntaxique est le plus fragile des trois, il ne doit pas
 * être le seul.
 *
 *  1. Seuls certains verbes d'ouverture sont acceptés, et une requête ne peut
 *     contenir qu'une seule instruction.
 *  2. La requête s'exécute dans une transaction déclarée READ ONLY : MariaDB
 *     rejette elle-même toute écriture, quelle que soit la façon dont elle
 *     serait passée à travers le filtre. La transaction est systématiquement
 *     annulée à la fin.
 *  3. Un temps d'exécution maximal évite qu'une jointure malheureuse
 *     n'immobilise la base.
 */
function cmdSql(string $argument): array
{
    $requete = trim($argument);

    if ($requete === '') {
        return [
            blocErreur('Usage : sql <SELECT …>'),
            blocTexte('Seules les requêtes de lecture sont acceptées '
                    . '(SELECT, SHOW, DESCRIBE, EXPLAIN, WITH).'),
        ];
    }

    // Un point-virgule final est naturel à taper : on l'accepte et on le
    // retire, mais un second signifierait plusieurs instructions.
    $requete = rtrim($requete, "; \t\n\r");

    if (str_contains($requete, ';')) {
        return [blocErreur('Une seule instruction à la fois : le point-virgule est refusé.')];
    }

    $verbesLecture = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH'];
    $premier = strtoupper((string) preg_replace('/^\W+/', '', strtok($requete, " \t\n(")));

    if (!in_array($premier, $verbesLecture, true)) {
        return [
            blocErreur('Requête refusée : la console est en lecture seule.'),
            blocTexte('Verbes acceptés : ' . implode(', ', $verbesLecture) . '.'),
            blocTexte('Les modifications passent par les pages dédiées de cette section.'),
        ];
    }

    /*
     * Quelques constructions restent interdites même en lecture : elles
     * écrivent sur le disque, lisent des fichiers, ou immobilisent le serveur.
     * Ce filtre complète la transaction en lecture seule, qui ne les couvre
     * pas toutes.
     */
    $interdits = ['INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE', 'SLEEP(', 'BENCHMARK(', 'GET_LOCK('];
    $majuscules = strtoupper($requete);

    foreach ($interdits as $interdit) {
        if (str_contains($majuscules, $interdit)) {
            return [blocErreur("Construction interdite dans une requête de console : $interdit")];
        }
    }

    $pdo = consolePdo();

    // Plafond de lignes : une requête sans LIMIT sur `historical` rapporterait
    // tout l'historique. On en ajoute un plutôt que de refuser la requête.
    $limiteAjoutee = false;
    if ($premier === 'SELECT' && !preg_match('/\bLIMIT\s+\d/i', $requete)) {
        $requete .= ' LIMIT ' . CONSOLE_SQL_LIGNES_MAX;
        $limiteAjoutee = true;
    }

    $debut = hrtime(true);

    try {
        // Filet de sécurité du serveur : la requête est tuée au-delà du délai.
        $pdo->exec('SET SESSION max_statement_time = ' . CONSOLE_SQL_SECONDES_MAX);

        /*
         * Le garde-fou décisif. Même si le filtrage ci-dessus était contourné,
         * MariaDB refuserait toute écriture dans cette transaction.
         */
        $pdo->exec('START TRANSACTION READ ONLY');

        $req = $pdo->query($requete);
        $lignes = $req->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // L'annulation est faite par le bloc finally, qui s'exécute avant que
        // ce return ne rende la main : la répéter ici serait redondant.
        return [blocErreur('Erreur SQL : ' . $e->getMessage())];
    } finally {
        // Toujours annulée : rien de ce qui a été fait ici ne doit survivre,
        // et la connexion est réutilisée par le reste de la requête HTTP.
        try {
            $pdo->exec('ROLLBACK');
            $pdo->exec('SET SESSION max_statement_time = 0');
        } catch (PDOException $e) {
            error_log('console/sql : annulation impossible — ' . $e->getMessage());
        }
    }

    $duree = round((hrtime(true) - $debut) / 1e6, 1);

    if (!$lignes) {
        return [blocSucces("Requête exécutée en {$duree} ms — aucune ligne.")];
    }

    $tronque = count($lignes) > CONSOLE_SQL_LIGNES_MAX;
    $lignes = array_slice($lignes, 0, CONSOLE_SQL_LIGNES_MAX);

    $blocs = [
        blocTableau(array_keys($lignes[0]), $lignes),
        blocTexte(count($lignes) . " ligne(s) en {$duree} ms."),
    ];

    if ($limiteAjoutee) {
        $blocs[] = blocTexte('Une clause LIMIT ' . CONSOLE_SQL_LIGNES_MAX
                           . ' a été ajoutée automatiquement.');
    }
    if ($tronque) {
        $blocs[] = blocTexte('Résultat tronqué à ' . CONSOLE_SQL_LIGNES_MAX . ' lignes.');
    }

    return $blocs;
}

/* -------------------------------------------------------------------------
 * Utilitaires d'arguments
 * ---------------------------------------------------------------------- */

/**
 * Entier lu dans un argument, borné.
 * Une saisie vide ou non numérique retombe sur la valeur par défaut plutôt que
 * de provoquer une erreur : dans un terminal, mieux vaut répondre que refuser.
 */
function consoleNombre(string $argument, int $defaut, int $maximum): int
{
    $argument = trim($argument);

    if ($argument === '' || !ctype_digit($argument)) {
        return $defaut;
    }

    return max(1, min($maximum, (int) $argument));
}

/**
 * Neutralise les jokers de LIKE dans un fragment de recherche.
 * Sans ça, chercher « % » ramènerait tout le catalogue, et « _ » fausserait
 * silencieusement la correspondance.
 */
function consoleEchapperLike(string $texte): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texte);
}
