<?php

class Config
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host = self::env('DB_HOST');
            $name = self::env('DB_NAME');
            $user = self::env('DB_USER');
            $pass = self::env('DB_PASS');

            try {
                self::$instance = new PDO(
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

        return self::$instance;
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