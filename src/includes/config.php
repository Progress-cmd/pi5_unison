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
        if (!self::estDemo()) {
            return self::env('DB_NAME');
        }

        // Par défaut « <base>_demo », surchargeable par DB_NAME_DEMO.
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
        $name = self::nomBase();

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