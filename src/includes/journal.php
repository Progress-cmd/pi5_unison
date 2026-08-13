<?php
/**
 * Journal d'activité et d'incidents — côté écriture.
 *
 * Ce fichier est inclus par includes/auth.php, donc par TOUTE page et TOUTE
 * action : il doit rester léger et surtout inoffensif. Deux règles tiennent
 * tout le reste :
 *
 *  1. journaliser() n'échoue jamais bruyamment. Une trace perdue est un
 *     désagrément ; une écoute interrompue ou une importation avortée parce
 *     que le journal n'a pas pu écrire serait une régression. Toute erreur est
 *     rattrapée et repliée sur error_log().
 *  2. Rien n'est fait avant le premier appel. Aucune connexion n'est ouverte
 *     tant qu'il n'y a rien à écrire — la lecture d'un flux audio ne paie pas
 *     l'existence du journal.
 *
 * La lecture, les statistiques et la purge vivent dans journalRapport.php,
 * qui n'est chargé que par la section d'administration.
 *
 * @see mysql_init/migrations/002_journal.sql pour la table
 * @see includes/journalRapport.php pour la consultation
 */

require_once __DIR__ . '/config.php';

/*
 * Niveaux, du plus anodin au plus grave. Le choix se résume à une question :
 * « qu'est-ce que ça demande à l'administrateur ? »
 *
 *   debug     — détail de mise au point, sans intérêt en exploitation
 *   info      — le cours normal des choses : connexion, import, suppression
 *   attention — anormal mais rattrapé : échec de connexion, image introuvable
 *   erreur    — l'opération demandée a échoué
 *   critique  — l'application est dégradée : base ou recherche injoignable
 */
const JOURNAL_NIVEAUX = ['debug', 'info', 'attention', 'erreur', 'critique'];

/*
 * Canaux : le « où » de l'événement, pour filtrer la page Journal.
 * Volontairement peu nombreux — une liste trop fine ne se filtre plus.
 */
const JOURNAL_CANAUX = [
    'auth'      => 'Connexions',
    'admin'     => 'Administration',
    'contenu'   => 'Contenu',
    'import'    => 'Importations',
    'stockage'  => 'Stockage',
    'recherche' => 'Recherche',
    'console'   => 'Console',
    'systeme'   => 'Système',
];

/** Longueurs maximales, alignées sur les colonnes de la table. */
const JOURNAL_MAX_MESSAGE  = 500;
const JOURNAL_MAX_CONTEXTE = 4000;

/**
 * Écrit un événement dans le journal.
 *
 * @param string $canal    une clé de JOURNAL_CANAUX
 * @param string $action   code court et stable : « connexion_reussie »
 * @param string $message  phrase lisible, affichée telle quelle
 * @param array  $contexte détails structurés (identifiants, compteurs…)
 * @param string $niveau   une valeur de JOURNAL_NIVEAUX
 * @param int|null $dureeMs durée de l'opération, pour les traitements longs
 */
function journaliser(
    string $canal,
    string $action,
    string $message = '',
    array $contexte = [],
    string $niveau = 'info',
    ?int $dureeMs = null
): void {
    /*
     * Garde-fou contre la récursion. Si l'écriture elle-même déclenche une
     * erreur PHP, le gestionnaire d'erreurs rappellerait journaliser(), qui
     * échouerait de nouveau : la requête partirait en boucle jusqu'à
     * saturation. Ce drapeau coupe le cycle au premier tour.
     */
    static $enCours = false;
    if ($enCours) {
        error_log("journal (récursion évitée) : [$canal/$action] $message");
        return;
    }

    $enCours = true;

    try {
        $pdo = Config::getConnectionPrincipale();

        // Un niveau ou un canal inconnu ne doit pas faire perdre l'événement :
        // on le range dans une valeur sûre plutôt que de refuser d'écrire.
        if (!in_array($niveau, JOURNAL_NIVEAUX, true)) {
            $niveau = 'info';
        }
        if (!isset(JOURNAL_CANAUX[$canal])) {
            $contexte['canal_demande'] = $canal;
            $canal = 'systeme';
        }

        // Une session de démonstration écrit dans la même table que les
        // autres : sans ce marqueur, ses traces seraient indiscernables d'une
        // activité réelle sur le contenu privé.
        if (!empty($_SESSION['user']['is_demo'])) {
            $contexte['demo'] = true;
        }

        $req = $pdo->prepare(
            "INSERT INTO journal
                (niveau, canal, action, message, user_id, utilisateur, ip, chemin, contexte, duree_ms)
             VALUES
                (:niveau, :canal, :action, :message, :user_id, :utilisateur, :ip, :chemin, :contexte, :duree)"
        );

        $req->execute([
            ':niveau'      => $niveau,
            ':canal'       => $canal,
            ':action'      => mb_substr($action, 0, 50),
            ':message'     => mb_substr($message, 0, JOURNAL_MAX_MESSAGE),
            ':user_id'     => journalUtilisateurId(),
            ':utilisateur' => journalUtilisateurNom(),
            ':ip'          => journalIp(),
            ':chemin'      => journalChemin(),
            ':contexte'    => journalContexte($contexte),
            ':duree'       => $dureeMs,
        ]);
    } catch (\Throwable $e) {
        /*
         * Dernier recours : le journal applicatif est inaccessible (base
         * arrêtée, table absente parce que la migration n'a pas été jouée…).
         * L'événement part dans les logs du conteneur, où il reste consultable
         * par « docker compose logs app ». C'est la seule voie qui ne dépende
         * de rien.
         */
        error_log("journal indisponible : [$canal/$action] $message — " . $e->getMessage());
    } finally {
        $enCours = false;
    }
}

/** Raccourci : événement normal. */
function journalInfo(string $canal, string $action, string $message = '', array $contexte = []): void
{
    journaliser($canal, $action, $message, $contexte, 'info');
}

/** Raccourci : anomalie rattrapée, qui mérite un œil sans être une panne. */
function journalAttention(string $canal, string $action, string $message = '', array $contexte = []): void
{
    journaliser($canal, $action, $message, $contexte, 'attention');
}

/** Raccourci : l'opération demandée a échoué. */
function journalErreur(string $canal, string $action, string $message = '', array $contexte = []): void
{
    journaliser($canal, $action, $message, $contexte, 'erreur');
}

/** Raccourci : l'application est dégradée, une intervention est nécessaire. */
function journalCritique(string $canal, string $action, string $message = '', array $contexte = []): void
{
    journaliser($canal, $action, $message, $contexte, 'critique');
}

/**
 * Chronomètre une opération et la journalise avec sa durée.
 *
 * Enveloppe le traitement plutôt que de laisser chaque appelant manipuler
 * hrtime() : la durée est ainsi mesurée de la même façon partout, et une
 * exception reste journalisée (en « erreur ») avant d'être relancée.
 *
 * @param callable $traitement reçoit un tableau de contexte par référence,
 *                             qu'il peut enrichir de ses propres compteurs
 * @return mixed la valeur renvoyée par le traitement
 */
function journalChronometre(string $canal, string $action, string $message, callable $traitement): mixed
{
    $debut = hrtime(true);
    $contexte = [];

    try {
        $resultat = $traitement($contexte);
    } catch (\Throwable $e) {
        journaliser(
            $canal,
            $action,
            $message . ' — échec : ' . $e->getMessage(),
            $contexte + ['exception' => get_class($e)],
            'erreur',
            (int) ((hrtime(true) - $debut) / 1e6)
        );
        throw $e;
    }

    journaliser($canal, $action, $message, $contexte, 'info', (int) ((hrtime(true) - $debut) / 1e6));

    return $resultat;
}

/* -------------------------------------------------------------------------
 * Contexte de la requête courante
 * ---------------------------------------------------------------------- */

/**
 * Identifiant du compte à l'origine de la requête, ou null.
 *
 * $_SESSION est lu directement, sans démarrer de session : appeler
 * session_start() ici enverrait un cookie depuis n'importe quel contexte, y
 * compris la diffusion audio qui ferme délibérément sa session.
 */
function journalUtilisateurId(): ?int
{
    $id = $_SESSION['user']['id'] ?? null;
    return $id === null ? null : (int) $id;
}

/** Nom du compte, recopié dans la ligne pour survivre à sa suppression. */
function journalUtilisateurNom(): ?string
{
    $nom = $_SESSION['user']['username'] ?? null;
    return $nom === null ? null : mb_substr((string) $nom, 0, 50);
}

/**
 * Adresse du client.
 *
 * L'application tourne derrière le conteneur Apache sur un réseau interne :
 * REMOTE_ADDR est la bonne source. Les en-têtes X-Forwarded-For ne sont pas
 * lus — ils sont librement falsifiables par le client, et les croire
 * permettrait de falsifier le journal.
 */
function journalIp(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return $ip === null ? null : mb_substr((string) $ip, 0, 45);
}

/**
 * Chemin appelé, sans la chaîne de requête.
 *
 * Les paramètres sont écartés : ils contiennent des termes de recherche et des
 * identifiants qui n'ont rien à faire dans un journal conservé des mois. Ce
 * qui compte vraiment est passé explicitement en contexte par l'appelant.
 */
function journalChemin(): ?string
{
    $uri = $_SERVER['REQUEST_URI'] ?? null;
    if ($uri === null) {
        // Exécution en ligne de commande (creerAdmin.php, scripts de maintenance).
        return PHP_SAPI === 'cli' ? 'cli' : null;
    }

    $chemin = strtok((string) $uri, '?');
    return mb_substr($chemin === false ? '' : $chemin, 0, 200);
}

/** Contexte encodé en JSON, tronqué, ou null s'il est vide. */
function journalContexte(array $contexte): ?string
{
    if ($contexte === []) {
        return null;
    }

    $json = json_encode($contexte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

    if ($json === false) {
        return json_encode(['contexte_illisible' => true]);
    }

    if (strlen($json) > JOURNAL_MAX_CONTEXTE) {
        // Tronquer du JSON le rend illisible : on remplace par un objet valide
        // qui dit ce qui s'est passé, plutôt qu'un fragment inexploitable.
        return json_encode([
            'contexte_tronque' => true,
            'taille'           => strlen($json),
            'debut'            => mb_substr($json, 0, 500),
        ], JSON_UNESCAPED_UNICODE);
    }

    return $json;
}

/* -------------------------------------------------------------------------
 * Capture des incidents PHP
 * ---------------------------------------------------------------------- */

/**
 * Branche le journal sur les exceptions non rattrapées et les erreurs fatales.
 *
 * C'est ce qui donne sa valeur au journal : sans ça, il ne contient que ce
 * qu'on a pensé à y écrire, et une panne réelle — celle qu'on n'a pas prévue —
 * n'y figure pas. En production les erreurs sont masquées à l'écran
 * (php-prod.ini) ; elles n'étaient jusqu'ici visibles que dans les logs du
 * conteneur, qu'il faut un accès SSH pour lire.
 *
 * Les avertissements ordinaires (E_WARNING, E_NOTICE) ne sont pas capturés :
 * l'application en produit au fil de l'eau sur des fichiers absents ou des
 * appels réseau, et les journaliser tous noierait le signal. Seul ce qui
 * interrompt la requête est enregistré.
 */
function journalInstallerHandlers(): void
{
    static $installe = false;
    if ($installe) {
        return;
    }
    $installe = true;

    /*
     * Le gestionnaire éventuellement déjà en place est récupéré puis rappelé à
     * la fin du nôtre : PHP n'en garde qu'un seul, et l'écraser sans façon
     * ferait disparaître le comportement de l'appelant précédent.
     * set_exception_handler(null) sert ici uniquement à le lire.
     */
    $precedent = set_exception_handler(null);

    set_exception_handler(function (\Throwable $e) use ($precedent): void {
        journaliser(
            'systeme',
            'exception_non_rattrapee',
            get_class($e) . ' : ' . $e->getMessage(),
            [
                'fichier' => journalFichierCourt($e->getFile()),
                'ligne'   => $e->getLine(),
                'trace'   => journalTraceCourte($e),
            ],
            'critique'
        );

        // Poser un gestionnaire prive PHP de sa propre trace : on la réécrit,
        // pour que « docker compose logs app » reste une source complète même
        // si c'est la base — donc le journal — qui est en cause.
        error_log('Exception non rattrapée : ' . $e->getMessage()
                . ' (' . journalFichierCourt($e->getFile()) . ':' . $e->getLine() . ')');

        if ($precedent !== null) {
            $precedent($e);
        }
    });

    /*
     * Les erreurs fatales (mémoire épuisée, appel de fonction inexistante) ne
     * passent par aucun gestionnaire d'exception : seule la fonction d'arrêt
     * permet de les voir. error_get_last() renvoie aussi les avertissements
     * bénins, d'où le filtre sur les types réellement fatals.
     */
    register_shutdown_function(function (): void {
        $erreur = error_get_last();

        if ($erreur === null) {
            return;
        }

        $fatales = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (!($erreur['type'] & $fatales)) {
            return;
        }

        journaliser(
            'systeme',
            'erreur_fatale',
            $erreur['message'],
            [
                'fichier' => journalFichierCourt($erreur['file']),
                'ligne'   => $erreur['line'],
                'type'    => $erreur['type'],
            ],
            'critique'
        );
    });
}

/**
 * Chemin réduit à sa partie utile.
 * « /var/www/html/src/actions/import.php » devient « src/actions/import.php » :
 * le préfixe est le même pour tout le monde et mange la largeur d'affichage.
 */
function journalFichierCourt(string $chemin): string
{
    $racine = '/var/www/html/';
    return str_starts_with($chemin, $racine) ? substr($chemin, strlen($racine)) : $chemin;
}

/**
 * Pile d'appels condensée : fichier et ligne des premiers niveaux.
 *
 * getTraceAsString() est bien trop verbeux pour une colonne de journal, et
 * contient les valeurs des arguments — donc potentiellement un mot de passe
 * passé à password_verify(). On ne garde que les emplacements.
 */
function journalTraceCourte(\Throwable $e, int $niveaux = 5): array
{
    $trace = [];

    foreach (array_slice($e->getTrace(), 0, $niveaux) as $cadre) {
        $trace[] = sprintf(
            '%s:%s %s',
            isset($cadre['file']) ? journalFichierCourt($cadre['file']) : '?',
            $cadre['line'] ?? '?',
            $cadre['function'] ?? '?'
        );
    }

    return $trace;
}
