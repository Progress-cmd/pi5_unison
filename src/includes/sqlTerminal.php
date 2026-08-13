<?php
/**
 * Terminal SQL de la section d'administration.
 *
 * Un vrai client SQL dans le navigateur : tout ce qui est tapé part à MariaDB,
 * à l'exception des méta-commandes préfixées d'une contre-oblique (\d, \dt,
 * \base, \ecriture), comme dans psql.
 *
 * DIFFÉRENCE AVEC LA CONSOLE
 * --------------------------
 * La console (includes/console.php) est en lecture seule par construction et
 * propose des commandes nommées qui encapsulent le schéma. Ce terminal-ci est
 * l'outil de dernier recours : il ne connaît rien au projet, et il peut écrire.
 *
 * LE MODÈLE DE SÉCURITÉ
 * ---------------------
 * Il repose sur trois idées, dans cet ordre d'importance :
 *
 *  1. LECTURE SEULE PAR DÉFAUT. À l'ouverture, seule la lecture est possible,
 *     et les requêtes s'exécutent dans une transaction READ ONLY — MariaDB
 *     refuse elle-même toute écriture. Il faut taper « \ecriture on » pour en
 *     sortir, et cette autorisation expire d'elle-même au bout d'un quart
 *     d'heure. On ne modifie donc jamais la base par accident.
 *
 *  2. GARDE-FOU « SAFE UPDATES ». Un UPDATE ou un DELETE sans WHERE est
 *     refusé, exactement comme le fait l'option --safe-updates du client mysql.
 *     C'est la faute la plus coûteuse et la plus facile à commettre.
 *
 *  3. INTERDITS DÉFINITIFS. Quelques instructions ne sont jamais acceptées,
 *     quel que soit le mode : elles touchent au serveur lui-même (comptes,
 *     privilèges), écrivent des fichiers, ou détruisent au-delà du réparable.
 *     Une interface web n'a pas à pouvoir les émettre.
 *
 * Tout ce qui écrit est journalisé avec la requête exacte : c'est ce qui
 * permettra de comprendre, plus tard, ce qui a été fait à la main.
 *
 * Aucune garde n'est posée dans ce fichier : c'est à l'appelant
 * (actions/admin/sql.php) de commencer par exigerAdmin().
 *
 * @see includes/console.php pour les blocs de sortie et la base courante
 */

require_once __DIR__ . '/console.php';

/** Durée de validité du mode écriture, en secondes. */
const SQL_ECRITURE_DUREE = 900;   // un quart d'heure

/** Nombre maximal de lignes rapportées par une requête. */
const SQL_LIGNES_MAX = 500;

/** Temps maximal accordé à une requête, en secondes. */
const SQL_SECONDES_MAX = 10;

/** Longueur au-delà de laquelle une valeur de cellule est tronquée. */
const SQL_CELLULE_MAX = 300;

/*
 * Verbes de lecture. Exécutés dans une transaction READ ONLY tant que le mode
 * écriture n'est pas actif.
 */
const SQL_VERBES_LECTURE = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH', 'ANALYZE'];

/*
 * Verbes d'écriture, autorisés seulement en mode écriture.
 * SET y figure : il ne modifie pas les données, mais il change le
 * comportement de la session (autocommit, contrôle des clés étrangères), ce
 * qui n'a de sens que si l'on s'apprête à écrire.
 */
const SQL_VERBES_ECRITURE = [
    'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE',
    'CREATE', 'ALTER', 'DROP', 'RENAME', 'SET', 'CALL', 'OPTIMIZE', 'REPAIR',
];

/**
 * Instructions refusées en toutes circonstances.
 *
 * Deux familles : ce qui touche au serveur MariaDB lui-même (comptes,
 * privilèges, bases entières), et ce qui franchit la frontière du serveur
 * (lecture ou écriture de fichiers). Aucune n'a de raison d'être émise depuis
 * l'administration d'une application de musique ; toutes ont des conséquences
 * qu'aucune sauvegarde de table ne rattrape.
 */
const SQL_INTERDITS = [
    'DROP DATABASE', 'DROP SCHEMA', 'CREATE DATABASE', 'CREATE SCHEMA', 'ALTER DATABASE',
    'GRANT ', 'REVOKE ', 'CREATE USER', 'DROP USER', 'ALTER USER', 'RENAME USER',
    'SET PASSWORD', 'FLUSH PRIVILEGES', 'CREATE TRIGGER', 'DROP TRIGGER',
    'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE', 'LOAD DATA', 'LOAD XML',
    'SHUTDOWN', 'INSTALL PLUGIN', 'UNINSTALL PLUGIN',
];

/* -------------------------------------------------------------------------
 * Mode écriture
 * ---------------------------------------------------------------------- */

/**
 * Le mode écriture est-il actif ?
 *
 * L'autorisation expire d'elle-même : un onglet laissé ouvert ne doit pas
 * rester indéfiniment capable d'écrire, et l'expiration force à retaper la
 * commande — donc à reprendre conscience de ce qu'on fait.
 */
function sqlEcritureActive(): bool
{
    $jusqu = $_SESSION['sql_ecriture_jusqu'] ?? 0;

    if ($jusqu > time()) {
        return true;
    }

    // Nettoyage : évite de garder une clé périmée en session.
    unset($_SESSION['sql_ecriture_jusqu']);
    return false;
}

/** Secondes restantes avant expiration du mode écriture. */
function sqlEcritureReste(): int
{
    return max(0, (int) (($_SESSION['sql_ecriture_jusqu'] ?? 0) - time()));
}

/**
 * Invite affichée par le terminal.
 * Elle porte la base ET le mode : c'est le seul rappel permanent qu'on est en
 * train de travailler sur une base modifiable.
 */
function sqlInvite(): string
{
    $mode = sqlEcritureActive()
        ? 'ÉCRITURE ' . ceil(sqlEcritureReste() / 60) . ' min'
        : 'lecture';

    return 'unison:' . consoleBaseCourante() . ' [' . $mode . '] =>';
}

/* -------------------------------------------------------------------------
 * Analyse d'une instruction
 * ---------------------------------------------------------------------- */

/**
 * Premier mot significatif d'une requête, en majuscules.
 *
 * Les commentaires SQL sont retirés d'abord : « /*x*&#47; DELETE … » commence
 * en apparence par un commentaire, et se lirait « » sans ce nettoyage.
 */
function sqlVerbe(string $requete): string
{
    $nu = sqlSansCommentaires($requete);
    $nu = ltrim($nu, "( \t\n\r");

    return strtoupper((string) strtok($nu, " \t\n\r("));
}

/**
 * Requête débarrassée de ses commentaires.
 *
 * Sert à l'analyse uniquement — c'est bien la requête d'origine qui est
 * exécutée. Sans ça, un commentaire suffirait à masquer le vrai verbe aux
 * contrôles ci-dessous.
 */
function sqlSansCommentaires(string $requete): string
{
    $sans = preg_replace('!/\*.*?\*/!s', ' ', $requete);      // /* … */
    $sans = preg_replace('/--[^\n]*/', ' ', (string) $sans);   // -- …
    $sans = preg_replace('/#[^\n]*/', ' ', (string) $sans);    // # …

    return (string) $sans;
}

/**
 * Instruction interdite détectée, ou null.
 *
 * La comparaison se fait sur une version normalisée — commentaires retirés,
 * espaces multiples réduits — sinon « DROP    DATABASE » ou
 * « DROP/**&#47;DATABASE » passeraient au travers.
 */
function sqlInterditDetecte(string $requete): ?string
{
    $normalise = strtoupper((string) preg_replace('/\s+/', ' ', sqlSansCommentaires($requete)));

    foreach (SQL_INTERDITS as $interdit) {
        if (str_contains($normalise, $interdit)) {
            return trim($interdit);
        }
    }

    return null;
}

/**
 * Un UPDATE ou un DELETE sans WHERE ?
 *
 * Reprend le principe de --safe-updates du client mysql. « DELETE FROM tracks »
 * est syntaxiquement correct et vide la table : c'est la faute la plus chère du
 * répertoire, et la seule que l'on puisse attraper de façon fiable.
 */
function sqlSansFiltre(string $requete): bool
{
    $verbe = sqlVerbe($requete);

    if (!in_array($verbe, ['UPDATE', 'DELETE'], true)) {
        return false;
    }

    $normalise = strtoupper(sqlSansCommentaires($requete));

    // Un LIMIT sans WHERE reste borné : c'est une intention explicite.
    return !preg_match('/\bWHERE\b/', $normalise) && !preg_match('/\bLIMIT\b/', $normalise);
}

/* -------------------------------------------------------------------------
 * Méta-commandes
 * ---------------------------------------------------------------------- */

/** Méta-commandes reconnues, pour l'aide et la complétion. */
function sqlMetaCommandes(): array
{
    return [
        '\aide'     => "Cette aide",
        '\tables'   => "Liste les tables de la base courante",
        '\d'        => "\\d <table> — colonnes, index et clés étrangères",
        '\base'     => "\\base [principale|demo] — change la base interrogée",
        '\ecriture' => "\\ecriture [on|off] — autorise les modifications (15 min)",
        '\effacer'  => "Vide l'écran",
    ];
}

/** Exécute une méta-commande. */
function sqlMeta(string $ligne): array
{
    $morceaux = preg_split('/\s+/', trim($ligne), 2, PREG_SPLIT_NO_EMPTY) ?: [];
    $nom = strtolower($morceaux[0] ?? '');
    $argument = trim($morceaux[1] ?? '');

    switch ($nom) {
        case '\aide':
        case '\?':
        case '\h':
            $lignes = [];
            foreach (sqlMetaCommandes() as $commande => $effet) {
                $lignes[] = [$commande, $effet];
            }

            return [
                blocTitre('Terminal SQL'),
                blocTexte("Tout ce qui n'est pas préfixé d'une contre-oblique part à MariaDB."),
                blocTableau(['Méta-commande', 'Effet'], $lignes),
                blocTexte(''),
                blocTexte('Entrée exécute · Maj+Entrée passe à la ligne · ↑ ↓ historique · Tab complète'),
                blocTexte('Une seule instruction à la fois ; le point-virgule final est facultatif.'),
                blocTexte(''),
                blocTitre('Mode courant'),
                blocTexte(sqlEcritureActive()
                    ? 'ÉCRITURE — les modifications sont possibles pendant encore '
                      . ceil(sqlEcritureReste() / 60) . ' min.'
                    : "Lecture seule. Les requêtes s'exécutent dans une transaction READ ONLY ; "
                      . '« \ecriture on » lève cette protection pour 15 minutes.'),
            ];

        case '\tables':
        case '\dt':
            return cmdTables('');

        case '\d':
        case '\decrire':
            if ($argument === '') {
                return [blocErreur('Usage : \d <table>')];
            }
            return array_merge(cmdDecrire($argument), sqlIndex($argument));

        case '\base':
        case '\c':
            return cmdBase($argument);

        case '\ecriture':
            return sqlBasculerEcriture($argument);

        default:
            return [
                blocErreur("Méta-commande inconnue : $nom"),
                blocTexte('« \aide » donne la liste.'),
            ];
    }
}

/** Index d'une table — complète \d, que DESCRIBE ne montre pas. */
function sqlIndex(string $table): array
{
    $pdo = consolePdo();

    // Nom vérifié contre information_schema avant toute interpolation, comme
    // dans cmdDecrire() : seul un nom réellement présent passe.
    $req = $pdo->prepare(
        "SELECT table_name FROM information_schema.TABLES
          WHERE table_schema = DATABASE() AND table_name = :nom"
    );
    $req->execute([':nom' => $table]);
    $reel = $req->fetchColumn();

    if ($reel === false) {
        return [];
    }

    $index = $pdo->query("SHOW INDEX FROM `$reel`")->fetchAll(PDO::FETCH_ASSOC);

    if (!$index) {
        return [];
    }

    $lignes = [];
    foreach ($index as $i) {
        $lignes[] = [
            $i['Key_name'],
            $i['Column_name'],
            $i['Non_unique'] ? 'non' : 'oui',
            $i['Seq_in_index'],
        ];
    }

    return [
        blocTitre('Index'),
        blocTableau(['Index', 'Colonne', 'Unique', 'Position'], $lignes),
    ];
}

/** Active ou coupe le mode écriture. */
function sqlBasculerEcriture(string $argument): array
{
    $argument = strtolower(trim($argument));

    if ($argument === 'off') {
        unset($_SESSION['sql_ecriture_jusqu']);

        journalInfo('console', 'sql_ecriture_off', 'Mode écriture du terminal SQL désactivé');

        return [blocSucces('Mode écriture désactivé — retour en lecture seule.')];
    }

    if ($argument !== 'on') {
        return [
            blocTexte('Mode courant : ' . (sqlEcritureActive()
                ? 'ÉCRITURE (encore ' . ceil(sqlEcritureReste() / 60) . ' min)'
                : 'lecture seule')),
            blocTexte('« \ecriture on » pour autoriser les modifications, « \ecriture off » pour les couper.'),
        ];
    }

    $_SESSION['sql_ecriture_jusqu'] = time() + SQL_ECRITURE_DUREE;

    /*
     * Journalisé en « attention » : ce n'est pas une modification en soi, mais
     * c'est le moment à partir duquel elles deviennent possibles. Dans une
     * enquête ultérieure, c'est la ligne qui donne le point de départ.
     */
    journalAttention('console', 'sql_ecriture_on',
        'Mode écriture du terminal SQL activé pour ' . (SQL_ECRITURE_DUREE / 60) . ' min',
        ['base' => consoleBaseCourante()]);

    return [
        blocAlerte('Mode ÉCRITURE actif pendant ' . (SQL_ECRITURE_DUREE / 60) . ' minutes.'),
        blocTexte('Les requêtes de modification sont désormais exécutées pour de bon, '
                . 'sans confirmation et sans possibilité d\'annulation.'),
        blocTexte('Une sauvegarde se prend en une commande sur le serveur : backup_unison'),
        blocTexte('UPDATE et DELETE sans WHERE restent refusés ; ajoutez « WHERE 1=1 » '
                . 'si vous visez réellement toute la table.'),
        blocTexte('« \ecriture off » coupe immédiatement.'),
    ];
}

/** Bloc d'avertissement — plus fort qu'un texte, moins qu'une erreur. */
function blocAlerte(string $texte): array
{
    return ['type' => 'alerte', 'texte' => $texte];
}

/* -------------------------------------------------------------------------
 * Exécution
 * ---------------------------------------------------------------------- */

/**
 * Interprète une ligne du terminal SQL.
 *
 * @return array{blocs: array, invite: string, ecriture: bool}
 */
function sqlExecuter(string $ligne): array
{
    $ligne = trim($ligne);

    if ($ligne === '') {
        return ['blocs' => [], 'invite' => sqlInvite(), 'ecriture' => sqlEcritureActive()];
    }

    try {
        $blocs = str_starts_with($ligne, '\\')
            ? sqlMeta($ligne)
            : sqlRequete($ligne);
    } catch (PDOException $e) {
        // Message de MariaDB affiché tel quel : « Unknown column 'titel' » est
        // exactement ce qu'on veut lire, et seul un administrateur le voit.
        $blocs = [blocErreur($e->getMessage())];
    } catch (\Throwable $e) {
        $blocs = [blocErreur('Erreur : ' . $e->getMessage())];
        error_log('sqlTerminal : ' . $e->getMessage());
    }

    return [
        'blocs'    => $blocs,
        'invite'   => sqlInvite(),
        'ecriture' => sqlEcritureActive(),
    ];
}

/** Exécute une instruction SQL après tous les contrôles. */
function sqlRequete(string $requete): array
{
    // Point-virgule final : naturel à taper, retiré. Un second signifierait
    // plusieurs instructions, que PDO::query ne doit pas recevoir.
    $requete = rtrim($requete, "; \t\n\r");

    if (str_contains(sqlSansCommentaires($requete), ';')) {
        return [blocErreur('Une seule instruction à la fois : le point-virgule est refusé.')];
    }

    // --- Barrière 1 : interdits définitifs ---
    $interdit = sqlInterditDetecte($requete);
    if ($interdit !== null) {
        journalAttention('console', 'sql_refuse',
            "Instruction refusée par le terminal SQL : $interdit",
            ['requete' => mb_substr($requete, 0, 500), 'motif' => $interdit]);

        return [
            blocErreur("Instruction refusée : « $interdit » n'est jamais autorisée ici."),
            blocTexte('Comptes, privilèges, bases entières et accès aux fichiers sortent du '
                    . "périmètre de l'application. Passez par un client SQL sur le serveur : "
                    . 'sql_unison'),
        ];
    }

    $verbe = sqlVerbe($requete);
    $lecture = in_array($verbe, SQL_VERBES_LECTURE, true);
    $ecriture = in_array($verbe, SQL_VERBES_ECRITURE, true);

    if (!$lecture && !$ecriture) {
        return [blocErreur("Verbe SQL non reconnu : « $verbe »")];
    }

    // --- Barrière 2 : lecture seule par défaut ---
    if ($ecriture && !sqlEcritureActive()) {
        return [
            blocErreur('Le terminal est en lecture seule.'),
            blocTexte("« \\ecriture on » autorise les modifications pour 15 minutes."),
        ];
    }

    // --- Barrière 3 : safe updates ---
    if (sqlSansFiltre($requete)) {
        return [
            blocErreur("$verbe sans WHERE : refusé."),
            blocTexte('Cette requête porterait sur toutes les lignes de la table. '
                    . 'Ajoutez « WHERE 1=1 » (ou un LIMIT) si c\'est bien l\'intention.'),
        ];
    }

    return $lecture
        ? sqlExecuterLecture($requete)
        : sqlExecuterEcriture($requete, $verbe);
}

/**
 * Requête de lecture, dans une transaction READ ONLY.
 *
 * Même quand le mode écriture est actif : une requête qui commence par SELECT
 * n'a aucune raison d'écrire, et la transaction l'en empêche quoi qu'il arrive.
 */
function sqlExecuterLecture(string $requete): array
{
    $pdo = consolePdo();
    $verbe = sqlVerbe($requete);

    /*
     * Plafond de lignes : un SELECT sans LIMIT sur `historical` rapporterait
     * tout l'historique. On en ajoute un plutôt que de refuser la requête.
     *
     * Sauf s'il n'y a pas de FROM : « SELECT NOW() » ne renvoie qu'une ligne,
     * et lui coller un LIMIT n'ajouterait qu'une note inutile sous chaque
     * réponse.
     */
    $nu = sqlSansCommentaires($requete);
    $limiteAjoutee = false;

    if ($verbe === 'SELECT'
        && preg_match('/\bFROM\b/i', $nu)
        && !preg_match('/\bLIMIT\s+\d/i', $nu)) {
        $requete .= ' LIMIT ' . SQL_LIGNES_MAX;
        $limiteAjoutee = true;
    }

    $debut = hrtime(true);

    try {
        $pdo->exec('SET SESSION max_statement_time = ' . SQL_SECONDES_MAX);
        $pdo->exec('START TRANSACTION READ ONLY');

        $req = $pdo->query($requete);
        $lignes = $req->fetchAll(PDO::FETCH_ASSOC);
    } finally {
        // Toujours annulée : la connexion est réutilisée par le reste de la
        // requête HTTP, elle ne doit pas rester dans une transaction ouverte.
        try {
            $pdo->exec('ROLLBACK');
            $pdo->exec('SET SESSION max_statement_time = 0');
        } catch (PDOException $e) {
            error_log('sqlTerminal : annulation impossible — ' . $e->getMessage());
        }
    }

    $duree = round((hrtime(true) - $debut) / 1e6, 1);

    journalInfo('console', 'sql_lecture',
        'SQL (lecture) : ' . mb_substr($requete, 0, 200),
        ['requete' => mb_substr($requete, 0, 500), 'lignes' => count($lignes),
         'base' => consoleBaseCourante(), 'duree_ms' => $duree]);

    if (!$lignes) {
        return [blocSucces("Aucune ligne. ({$duree} ms)")];
    }

    $tronque = count($lignes) > SQL_LIGNES_MAX;
    $lignes = array_slice($lignes, 0, SQL_LIGNES_MAX);

    $blocs = [
        blocTableau(array_keys($lignes[0]), array_map('sqlLigne', $lignes)),
        blocTexte(count($lignes) . " ligne(s) en {$duree} ms."),
    ];

    if ($limiteAjoutee) {
        $blocs[] = blocTexte('LIMIT ' . SQL_LIGNES_MAX . ' ajouté automatiquement.');
    }
    if ($tronque) {
        $blocs[] = blocTexte('Résultat tronqué à ' . SQL_LIGNES_MAX . ' lignes.');
    }

    return $blocs;
}

/**
 * Requête de modification.
 *
 * Journalisée AVANT exécution : si la requête fait tomber le serveur ou
 * n'aboutit jamais, la trace de ce qui a été tenté doit exister quand même.
 * C'est la seule chose qui permettra de comprendre l'état trouvé ensuite.
 */
function sqlExecuterEcriture(string $requete, string $verbe): array
{
    $pdo = consolePdo();

    // Le DDL est irréversible et ne se voit pas dans une sauvegarde de données :
    // il monte d'un cran dans le journal.
    $ddl = in_array($verbe, ['CREATE', 'ALTER', 'DROP', 'RENAME', 'TRUNCATE'], true);

    journaliser('console', 'sql_ecriture',
        'SQL (écriture) : ' . mb_substr($requete, 0, 200),
        ['requete' => mb_substr($requete, 0, 500), 'verbe' => $verbe,
         'base' => consoleBaseCourante(), 'ddl' => $ddl],
        $ddl ? 'critique' : 'attention');

    $debut = hrtime(true);

    try {
        $pdo->exec('SET SESSION max_statement_time = ' . SQL_SECONDES_MAX);
        $affectees = $pdo->exec($requete);

        /*
         * Relevé IMMÉDIATEMENT, avant toute autre écriture sur cette connexion.
         * Le journal vit dans la même base, donc sur la même instance PDO : la
         * ligne de trace écrite juste après remplacerait ce dernier identifiant
         * par le sien, et le terminal annoncerait l'identifiant de sa propre
         * trace au lieu de celui de la ligne insérée.
         */
        $insere = $verbe === 'INSERT' ? $pdo->lastInsertId() : null;
    } finally {
        try {
            $pdo->exec('SET SESSION max_statement_time = 0');
        } catch (PDOException $e) {
            error_log('sqlTerminal : ' . $e->getMessage());
        }
    }

    $duree = round((hrtime(true) - $debut) / 1e6, 1);

    // Le résultat réel est journalisé à son tour : la trace précédente dit ce
    // qui a été tenté, celle-ci dit ce que ça a fait.
    journalAttention('console', 'sql_ecriture_resultat',
        sprintf('%s : %d ligne(s) affectée(s)', $verbe, (int) $affectees),
        ['verbe' => $verbe, 'affectees' => (int) $affectees,
         'requete' => mb_substr($requete, 0, 500)]);

    $blocs = [
        blocSucces(sprintf('%d ligne(s) affectée(s) en %s ms.', (int) $affectees, $duree)),
    ];

    if ($insere !== null && $insere !== '' && $insere !== '0') {
        $blocs[] = blocTexte('Identifiant inséré : ' . $insere);
    }

    if ($ddl) {
        $blocs[] = blocAlerte('Modification de structure : elle ne figure dans aucune '
                            . 'migration. Reportez-la dans mysql_init/migrations/ pour '
                            . 'que les autres installations la reçoivent.');
    }

    return $blocs;
}

/**
 * Prépare une ligne de résultat pour l'affichage.
 *
 * NULL devient « ∅ » : dans un client SQL, confondre une valeur nulle avec une
 * chaîne vide conduit à des diagnostics faux. Les valeurs binaires ou très
 * longues sont résumées plutôt que déversées.
 */
function sqlLigne(array $ligne): array
{
    $cellules = [];

    foreach ($ligne as $valeur) {
        if ($valeur === null) {
            $cellules[] = '∅';
            continue;
        }

        $texte = (string) $valeur;

        // Contenu binaire : illisible, et capable de casser l'encodage JSON.
        if (!mb_check_encoding($texte, 'UTF-8')) {
            $cellules[] = '(binaire, ' . strlen($texte) . ' octets)';
            continue;
        }

        $cellules[] = mb_strlen($texte) > SQL_CELLULE_MAX
            ? mb_substr($texte, 0, SQL_CELLULE_MAX) . '…'
            : $texte;
    }

    return $cellules;
}
