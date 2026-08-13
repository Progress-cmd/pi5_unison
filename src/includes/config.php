<?php

class Config
{
    /**
     * Connexions ouvertes, indexées par nom de base.
     *
     * Un tableau plutôt qu'une seule instance : si getConnection() était
     * appelé avant que la session soit démarrée, une instance unique figerait
     * la mauvaise base pour tout le reste de la requête. Ici, chaque base a la
     * sienne et l'aiguillage reste juste en toutes circonstances.
     */
    private static array $instances = [];

    /**
     * Une session de démonstration travaille sur une base entièrement
     * distincte. C'est ici, et nulle part ailleurs, que l'aiguillage se fait :
     * toutes les requêtes de l'application en héritent sans être modifiées, et
     * aucune donnée personnelle n'est atteignable depuis la démonstration —
     * même en cas d'oubli de filtre dans une requête.
     */
    public static function estDemo(): bool
    {
        // On lit $_SESSION plutôt que session_status() : stream.php ferme la
        // session avant de diffuser, sans que la nature de la session change.
        return !empty($_SESSION['user']['is_demo']);
    }

    /** Nom de la base à utiliser pour la requête en cours. */
    public static function nomBase(): string
    {
        return self::estDemo() ? self::nomBaseDemo() : self::env('DB_NAME');
    }

    /** Nom de la base de démonstration : « <base>_demo », ou DB_NAME_DEMO. */
    public static function nomBaseDemo(): string
    {
        $demo = getenv('DB_NAME_DEMO');
        return $demo !== false && $demo !== '' ? $demo : self::env('DB_NAME') . '_demo';
    }

    /**
     * Dossier des fichiers audio. La démonstration a le sien : un nom de
     * fichier personnel n'y existe simplement pas, ce qui rend la diffusion
     * du catalogue privé impossible plutôt que seulement interdite.
     */
    public static function cheminMusiques(): string
    {
        return self::estDemo() ? '/var/www/music_data/demo/' : '/var/www/music_data/';
    }

    /** Nom d'index Meilisearch correspondant à la session en cours. */
    public static function indexMeili(string $base): string
    {
        return self::estDemo() ? $base . '_demo' : $base;
    }

    public static function getConnection(): PDO
    {
        return self::connexion(self::nomBase());
    }

    /**
     * Connexion à la base principale, quelle que soit la session.
     *
     * Sert au journal (includes/journal.php) : une trace doit être écrite au
     * même endroit pour tout le monde, sinon l'activité d'une session de
     * démonstration atterrirait dans la base de démonstration — que la section
     * d'administration n'ouvre jamais, et qui est remise à zéro. Le journal est
     * une donnée d'exploitation, pas du contenu utilisateur : il ne suit pas
     * l'aiguillage.
     *
     * À n'utiliser que pour ça. Toute lecture ou écriture de contenu passe par
     * getConnection(), sans quoi l'isolement de la démonstration tomberait.
     *
     * Lève une PDOException au lieu d'interrompre la requête : l'appelant est
     * le journal, et une base injoignable ne doit pas transformer une panne
     * silencieuse en page blanche.
     *
     * @throws PDOException
     */
    public static function getConnectionPrincipale(): PDO
    {
        return self::connexion(self::env('DB_NAME'), false);
    }

    /**
     * Connexion à la base de démonstration, depuis une session qui n'en est
     * pas une.
     *
     * Réservée à la console d'administration, qui doit pouvoir inspecter les
     * deux bases du projet — et où toute commande est en lecture seule. Aucune
     * page ni action de l'application ne doit l'appeler : l'aiguillage
     * automatique de getConnection() est ce qui garantit qu'une session de
     * démonstration ne voit jamais le contenu réel, et l'inverse.
     *
     * Lève une PDOException si la base de démonstration n'existe pas —
     * l'installation ne l'impose pas.
     *
     * @throws PDOException
     */
    public static function getConnectionDemo(): PDO
    {
        return self::connexion(self::nomBaseDemo(), false);
    }

    /**
     * Ouvre (ou réutilise) la connexion vers une base nommée.
     *
     * @param bool $fatal true pour interrompre la requête si la base est
     *                    injoignable (comportement historique de
     *                    getConnection : aucune page n'a de sens sans base),
     *                    false pour laisser l'exception remonter.
     */
    private static function connexion(string $name, bool $fatal = true): PDO
    {
        if (!isset(self::$instances[$name])) {
            $host = self::env('DB_HOST');
            $user = self::env('DB_USER');
            $pass = self::env('DB_PASS');

            try {
                self::$instances[$name] = new PDO(
                    "mysql:host=$host;dbname=$name;charset=utf8mb4",
                    $user,
                    $pass,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );
            } catch (PDOException $e) {
                error_log($e->getMessage());
                if (!$fatal) {
                    throw $e;
                }
                die("Erreur de connexion à la base de données.");
            }
        }

        return self::$instances[$name];
    }

    private static function env(string $key): string
    {
        $value = getenv($key);
        if ($value === false) {
            throw new RuntimeException("Variable d'environnement manquante : $key");
        }
        return $value;
    }
}