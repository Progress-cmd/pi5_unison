<?php
require __DIR__ . '/../../vendor/autoload.php';
use Meilisearch\Client;

include_once __DIR__ . "/config.php";
$pdo = Config::getConnection();

// Connexion Meilisearch
$meiliKey = getenv('MS_PASS') ?? null;
$client = new Client('http://ms:7700', $meiliKey);

// --- Indexation musiques ---
$sql = "SELECT id AS id_music, title AS title_music FROM tracks";
$musiques = $pdo->query($sql)->fetchAll();

// Crée l’index s’il n’existe pas
$client->createIndex('musiques', ['primaryKey' => 'id_music']);
// Récupère l’objet index
$indexMusiques = $client->index('musiques');

// Supprimer tous les documents
$task = $indexMusiques->deleteAllDocuments();
$client->waitForTask($task['taskUid']);

// Ajouter les documents
$task = $indexMusiques->addDocuments($musiques);
$client->waitForTask($task['taskUid']);

// Définir les champs recherchables
$task = $indexMusiques->updateSearchableAttributes(['title_music']);
$client->waitForTask($task['taskUid']);

// --- Indexation artistes ---
$sql = "SELECT id AS id_artist, name AS name_artist FROM artists";
$artistes = $pdo->query($sql)->fetchAll();

// Crée l’index s’il n’existe pas
$client->createIndex('artists', ['primaryKey' => 'id_artist']);
// Récupère l’objet index
$indexArtists = $client->index('artists');

// Supprimer tous les documents
$task = $indexArtists->deleteAllDocuments();
$client->waitForTask($task['taskUid']);

// Ajouter les documents
$task = $indexArtists->addDocuments($artistes);
$client->waitForTask($task['taskUid']);

// Définir les champs recherchables
$task = $indexArtists->updateSearchableAttributes(['name_artist']);
$client->waitForTask($task['taskUid']);

echo "Indexation terminée ! " . count($musiques) . " musiques et " . count($artistes) . " artistes envoyés.";