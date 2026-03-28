<?php

class Config
{
    public static string $HOST;
    public static string $NAME;
    public static string $USER;
    public static string $PASS;
}

Config::$HOST = getenv('DB_HOST');
Config::$NAME = getenv('DB_NAME');
Config::$USER = getenv('DB_USER');
Config::$PASS = getenv('DB_PASS');
$charset = 'utf8mb4';

$options =
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lance des erreurs si bug
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retourne des tableaux propres
        PDO::ATTR_EMULATE_PREPARES   => false                  // Protection injection SQL réelle
    ];

try
{
    $pdo = new PDO("mysql:host=".Config::$HOST.";dbname=".Config::$NAME.";charset=".$charset,
        Config::$USER,
        Config::$PASS,
        $options);
}
catch (\PDOException $e)
{
    error_log($e->getMessage());
    die("Erreur de connexion à la base de données.");
}