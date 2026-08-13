<?php
/**
 * Journal d'activité — côté lecture.
 *
 * Séparé de journal.php à dessein : l'écriture est incluse partout, la lecture
 * ne l'est que par la section d'administration. Ce fichier peut donc être aussi
 * bavard que nécessaire sans peser sur une lecture audio.
 *
 * Toutes les fonctions travaillent sur la base principale : c'est la seule où
 * le journal existe (voir Config::getConnectionPrincipale).
 *
 * Aucune garde n'est posée ici : c'est aux appelants de commencer par
 * exigerAdmin().
 *
 * @see includes/journal.php pour l'écriture et la liste des canaux
 */

require_once __DIR__ . '/journal.php';

/** Nombre d'événements par page dans la consultation. */
const JOURNAL_PAR_PAGE = 60;

/**
 * La table du journal existe-t-elle ?
 *
 * La migration 002 doit être jouée à la main sur une base déjà en service :
 * tant qu'elle ne l'est pas, les pages doivent l'expliquer plutôt que tomber
 * sur une erreur SQL incompréhensible.
 */
function journalTableExiste(PDO $pdo): bool
{
    static $existe = null;

    if ($existe === null) {
        try {
            $pdo->query("SELECT 1 FROM journal LIMIT 1");
            $existe = true;
        } catch (PDOException $e) {
            $existe = false;
        }
    }

    return $existe;
}

/**
 * Construit la clause WHERE et ses paramètres à partir des filtres reçus.
 *
 * Facteur commun de la liste et du comptage : les deux doivent voir exactement
 * le même sous-ensemble, sinon la pagination annonce un total qui ne
 * correspond pas aux lignes affichées.
 *
 * Filtres reconnus : niveau, canal, action, user_id, heures, recherche.
 *
 * @return array{0: string, 1: array}
 */
function journalClauses(array $filtres): array
{
    $conditions = [];
    $params = [];

    if (!empty($filtres['niveau']) && in_array($filtres['niveau'], JOURNAL_NIVEAUX, true)) {
        /*
         * Un niveau demandé vaut « ce niveau ET tout ce qui est plus grave » :
         * chercher les erreurs sans voir les événements critiques serait un
         * piège. L'ordre est celui de JOURNAL_NIVEAUX, pas l'ordre alphabétique
         * — d'où la liste explicite plutôt qu'une comparaison SQL.
         */
        $rang = array_search($filtres['niveau'], JOURNAL_NIVEAUX, true);
        $retenus = array_slice(JOURNAL_NIVEAUX, $rang);

        $marqueurs = [];
        foreach ($retenus as $i => $niveau) {
            $marqueurs[] = ":niveau$i";
            $params[":niveau$i"] = $niveau;
        }
        $conditions[] = 'niveau IN (' . implode(', ', $marqueurs) . ')';
    }

    if (!empty($filtres['canal']) && isset(JOURNAL_CANAUX[$filtres['canal']])) {
        $conditions[] = 'canal = :canal';
        $params[':canal'] = $filtres['canal'];
    }

    if (!empty($filtres['action'])) {
        $conditions[] = 'action = :action';
        $params[':action'] = (string) $filtres['action'];
    }

    if (!empty($filtres['user_id'])) {
        $conditions[] = 'user_id = :user_id';
        $params[':user_id'] = (int) $filtres['user_id'];
    }

    if (!empty($filtres['heures'])) {
        /*
         * Entier interpolé plutôt que paramètre lié : l'opérande d'un INTERVAL
         * n'accepte pas partout un marqueur en requête préparée native. La
         * valeur est bornée et castée ici — elle ne peut être qu'un nombre.
         */
        $heures = max(1, min(8760, (int) $filtres['heures'])); // plafond : un an
        $conditions[] = "horodatage >= NOW() - INTERVAL $heures HOUR";
    }

    if (!empty($filtres['recherche'])) {
        /*
         * Recherche libre sur les colonnes lisibles. Les caractères jokers de
         * LIKE sont échappés : sans ça, un « % » saisi par curiosité ramènerait
         * tout, et un « _ » fausserait silencieusement le résultat.
         */
        $motif = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filtres['recherche']) . '%';

        /*
         * Un paramètre nommé par colonne, et non le même répété quatre fois :
         * les requêtes préparées natives (ATTR_EMULATE_PREPARES à false, voir
         * Config) n'acceptent pas qu'un marqueur apparaisse plusieurs fois
         * dans la même requête — elles rejettent l'exécution.
         */
        $cibles = ['message', 'action', 'utilisateur', 'contexte'];
        $tests = [];

        foreach ($cibles as $i => $colonne) {
            $tests[] = "$colonne LIKE :recherche$i";
            $params[":recherche$i"] = $motif;
        }

        $conditions[] = '(' . implode(' OR ', $tests) . ')';
    }

    $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

    return [$where, $params];
}

/**
 * Page d'événements, du plus récent au plus ancien.
 *
 * @param int $page numéro de page, à partir de 1
 */
function journalLister(PDO $pdo, array $filtres = [], int $page = 1): array
{
    if (!journalTableExiste($pdo)) {
        return [];
    }

    [$where, $params] = journalClauses($filtres);
    $offset = max(0, ($page - 1) * JOURNAL_PAR_PAGE);

    /*
     * LIMIT et OFFSET sont interpolés et non liés : en mode « prepares » non
     * émulées, MariaDB refuse un paramètre à cet endroit. Les deux valeurs sont
     * des entiers calculés ici, jamais des chaînes venues du client.
     */
    $req = $pdo->prepare(
        "SELECT id, horodatage, niveau, canal, action, message,
                user_id, utilisateur, ip, chemin, contexte, duree_ms
           FROM journal" . $where . "
          ORDER BY horodatage DESC, id DESC
          LIMIT " . JOURNAL_PAR_PAGE . " OFFSET " . $offset
    );

    $req->execute($params);

    return $req->fetchAll(PDO::FETCH_ASSOC);
}

/** Nombre total d'événements correspondant aux filtres. */
function journalCompter(PDO $pdo, array $filtres = []): int
{
    if (!journalTableExiste($pdo)) {
        return 0;
    }

    [$where, $params] = journalClauses($filtres);

    $req = $pdo->prepare("SELECT COUNT(*) FROM journal" . $where);
    $req->execute($params);

    return (int) $req->fetchColumn();
}

/**
 * Chiffres de tête : volume, répartition par niveau, incidents récents.
 *
 * Une seule fonction pour tout le bandeau de la page Journal, afin de ne pas
 * multiplier les allers-retours pour trois compteurs.
 */
function journalStatistiques(PDO $pdo): array
{
    if (!journalTableExiste($pdo)) {
        return [
            'total' => 0, 'jour' => 0, 'semaine' => 0,
            'par_niveau' => [], 'par_canal' => [],
            'incidents_24h' => 0, 'plus_ancien' => null,
        ];
    }

    $stats = [
        'total'   => (int) $pdo->query("SELECT COUNT(*) FROM journal")->fetchColumn(),
        'jour'    => (int) $pdo->query("SELECT COUNT(*) FROM journal
                                         WHERE horodatage >= NOW() - INTERVAL 1 DAY")->fetchColumn(),
        'semaine' => (int) $pdo->query("SELECT COUNT(*) FROM journal
                                         WHERE horodatage >= NOW() - INTERVAL 7 DAY")->fetchColumn(),
        // Ce qui appelle une décision : erreurs et incidents critiques du jour.
        'incidents_24h' => (int) $pdo->query("SELECT COUNT(*) FROM journal
                                               WHERE niveau IN ('erreur', 'critique')
                                                 AND horodatage >= NOW() - INTERVAL 1 DAY")->fetchColumn(),
        'plus_ancien' => $pdo->query("SELECT MIN(horodatage) FROM journal")->fetchColumn() ?: null,
    ];

    $stats['par_niveau'] = [];
    foreach ($pdo->query("SELECT niveau, COUNT(*) AS n FROM journal
                           WHERE horodatage >= NOW() - INTERVAL 7 DAY
                           GROUP BY niveau") as $ligne) {
        $stats['par_niveau'][$ligne['niveau']] = (int) $ligne['n'];
    }

    $stats['par_canal'] = [];
    foreach ($pdo->query("SELECT canal, COUNT(*) AS n FROM journal
                           WHERE horodatage >= NOW() - INTERVAL 7 DAY
                           GROUP BY canal ORDER BY n DESC") as $ligne) {
        $stats['par_canal'][$ligne['canal']] = (int) $ligne['n'];
    }

    return $stats;
}

/**
 * Volume d'événements par jour, au format attendu par grapheBarres().
 *
 * Les jours sans activité sont remplis à zéro : un graphique qui saute les
 * jours vides suggère une activité continue là où il n'y en a pas eu.
 *
 * @see includes/adminGraphes.php
 */
function journalParJour(PDO $pdo, int $jours = 14): array
{
    if (!journalTableExiste($pdo)) {
        return [];
    }

    // Borné puis interpolé, comme dans journalClauses() et pour la même raison.
    $jours = max(1, min(365, $jours));

    $req = $pdo->query(
        "SELECT DATE(horodatage) AS jour, COUNT(*) AS n
           FROM journal
          WHERE horodatage >= CURDATE() - INTERVAL $jours DAY
          GROUP BY jour"
    );

    $mesures = [];
    foreach ($req->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
        $mesures[$ligne['jour']] = (int) $ligne['n'];
    }

    $serie = [];
    for ($i = $jours; $i >= 0; $i--) {
        $jour = date('Y-m-d', strtotime("-$i day"));
        $serie[] = [
            'libelle' => date('d/m', strtotime($jour)),
            'valeur'  => $mesures[$jour] ?? 0,
        ];
    }

    return $serie;
}

/**
 * Les derniers incidents (erreur ou critique), pour le tableau de bord.
 * C'est le seul extrait du journal affiché hors de sa page dédiée.
 */
function journalIncidentsRecents(PDO $pdo, int $limite = 5): array
{
    if (!journalTableExiste($pdo)) {
        return [];
    }

    $limite = max(1, min(50, $limite));

    $req = $pdo->query(
        "SELECT id, horodatage, niveau, canal, action, message, utilisateur
           FROM journal
          WHERE niveau IN ('erreur', 'critique')
          ORDER BY horodatage DESC, id DESC
          LIMIT $limite"
    );

    return $req->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Supprime les événements plus vieux que $jours.
 *
 * Le journal n'est pas une archive : il sert à comprendre ce qui vient de se
 * passer. Sans purge, il grossit indéfiniment sur une machine dont l'espace
 * disque est déjà le sujet de la page Stockage.
 *
 * @return int nombre de lignes supprimées
 */
function journalPurger(PDO $pdo, int $jours): int
{
    if (!journalTableExiste($pdo)) {
        return 0;
    }

    // Un âge nul viderait la table entière : la purge est un entretien, pas un
    // effacement. Une journée est le minimum, pour garder de quoi diagnostiquer.
    $jours = max(1, min(3650, $jours));

    // Entier borné et interpolé (voir journalClauses).
    $req = $pdo->query("DELETE FROM journal WHERE horodatage < NOW() - INTERVAL $jours DAY");

    return $req->rowCount();
}

/**
 * Rétention par défaut, en jours.
 * Assez long pour couvrir une absence prolongée, assez court pour que la table
 * reste petite sur un catalogue familial.
 */
const JOURNAL_RETENTION_JOURS = 90;

/**
 * Purge d'entretien, déclenchée par la consultation de la section
 * d'administration.
 *
 * Il n'y a ni tâche planifiée ni cron dans ce projet : accrocher l'entretien à
 * une visite est le seul mécanisme qui ne dépende de rien d'extérieur. Le
 * verrou sur fichier évite qu'elle se déclenche à chaque affichage — une fois
 * par jour suffit, et deux onglets ouverts ne doivent pas la lancer deux fois.
 *
 * @return int nombre de lignes supprimées (0 si la purge n'avait pas lieu d'être)
 */
function journalPurgeAutomatique(PDO $pdo): int
{
    $temoin = '/tmp/unison_journal_purge';

    // Déjà passée dans les dernières 24 h : rien à faire.
    if (is_file($temoin) && (time() - (int) @filemtime($temoin)) < 86400) {
        return 0;
    }

    // Le témoin est daté AVANT la purge : si celle-ci échoue, on ne veut pas
    // qu'elle soit retentée à chaque affichage de page.
    @touch($temoin);

    try {
        $supprimes = journalPurger($pdo, JOURNAL_RETENTION_JOURS);
    } catch (PDOException $e) {
        error_log('journalPurgeAutomatique : ' . $e->getMessage());
        return 0;
    }

    if ($supprimes > 0) {
        journalInfo('systeme', 'journal_purge_auto',
            "$supprimes événement(s) de plus de " . JOURNAL_RETENTION_JOURS . " jours supprimés",
            ['supprimes' => $supprimes, 'retention_jours' => JOURNAL_RETENTION_JOURS]);
    }

    return $supprimes;
}

/**
 * Poids de la table du journal sur le disque, en octets.
 *
 * information_schema donne une estimation (InnoDB ne compte pas les lignes
 * exactement), suffisante pour décider s'il faut purger.
 */
function journalTailleTable(PDO $pdo): int
{
    if (!journalTableExiste($pdo)) {
        return 0;
    }

    try {
        $req = $pdo->prepare(
            "SELECT COALESCE(data_length + index_length, 0)
               FROM information_schema.TABLES
              WHERE table_schema = DATABASE() AND table_name = 'journal'"
        );
        $req->execute();

        return (int) $req->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/** Libellé lisible d'un canal, pour l'affichage. */
function journalLibelleCanal(string $canal): string
{
    return JOURNAL_CANAUX[$canal] ?? $canal;
}

/**
 * Écart, en secondes, entre l'horloge de PHP et celle de MariaDB.
 *
 * Les deux conteneurs ne sont pas dans le même fuseau : PHP suit
 * date.timezone (Europe/Paris), MariaDB tourne en UTC. Les horodatages du
 * journal sont posés par la base (current_timestamp) et filtrés par elle
 * (NOW(), CURDATE()) : stockage et filtres sont donc cohérents entre eux.
 * Seul l'affichage, calculé en PHP, doit être ramené sur la même horloge —
 * sans quoi un événement à peine écrit s'annonce « il y a 2 h ».
 *
 * Mesuré plutôt que codé en dur : la correction reste juste si l'un des deux
 * fuseaux change, et au passage à l'heure d'hiver.
 */
function journalDecalageSql(): int
{
    static $decalage = null;

    if ($decalage === null) {
        try {
            $sql = Config::getConnectionPrincipale()->query('SELECT NOW()')->fetchColumn();
            $instant = strtotime((string) $sql);
            $decalage = $instant === false ? 0 : time() - $instant;
        } catch (\Throwable $e) {
            // Sans base, l'affichage relatif est le moindre des problèmes.
            $decalage = 0;
        }
    }

    return $decalage;
}

/**
 * Horodatage en français relatif : « il y a 4 min », « hier à 21:03 ».
 * Un journal se lit en « quand par rapport à maintenant », pas en dates
 * absolues qu'il faut soustraire de tête.
 */
function journalQuand(string $horodatage): string
{
    $instant = strtotime($horodatage);
    if ($instant === false) {
        return $horodatage;
    }

    // Ramené sur l'horloge de PHP avant toute comparaison ou mise en forme.
    $instant += journalDecalageSql();

    $ecart = time() - $instant;

    if ($ecart < 60) {
        return "à l'instant";
    }
    if ($ecart < 3600) {
        return 'il y a ' . intdiv($ecart, 60) . ' min';
    }
    if ($ecart < 86400) {
        return 'il y a ' . intdiv($ecart, 3600) . ' h';
    }
    if ($ecart < 172800) {
        return 'hier à ' . date('H:i', $instant);
    }

    return date('d/m/Y à H:i', $instant);
}
