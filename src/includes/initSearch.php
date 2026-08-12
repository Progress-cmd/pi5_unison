<?php
/**
 * (Ré)indexe Meilisearch depuis la base. Lancé au démarrage du conteneur.
 *
 * Deux jeux d'index sont maintenus : ceux de l'application, et ceux suffixés
 * « _demo » alimentés par la base de démonstration. Ils ne se croisent jamais.
 */
require __DIR__ . '/../../vendor/autoload.php';
use Meilisearch\Client;

include_once __DIR__ . "/config.php";

// Connexion Meilisearch
$meiliKey = getenv('MS_PASS') ?? null;
$client = new Client('http://ms:7700', $meiliKey);

/**
 * Reconstruit un index à partir d'une requête SQL.
 *
 * @param string $nom      nom de l'index Meilisearch
 * @param string $cle      clé primaire des documents
 * @param string $sql      requête produisant les documents
 * @param array  $champs   attributs rendus recherchables
 */
function indexer(Client $client, PDO $pdo, string $nom, string $cle, string $sql, array $champs): int
{
    $documents = $pdo->query($sql)->fetchAll();

    // Crée l'index s'il n'existe pas
    $client->createIndex($nom, ['primaryKey' => $cle]);
    $index = $client->index($nom);

    // Repart d'un index vide : l'indexation est un remplacement, pas un ajout
    $task = $index->deleteAllDocuments();
    $client->waitForTask($task['taskUid']);

    if ($documents) {
        $task = $index->addDocuments($documents);
        $client->waitForTask($task['taskUid']);
    }

    $task = $index->updateSearchableAttributes($champs);
    $client->waitForTask($task['taskUid']);

    return count($documents);
}

/** Ouvre une connexion sur une base nommée, hors du singleton de Config. */
function connexionVers(string $base): ?PDO
{
    try {
        return new PDO(
            'mysql:host=' . getenv('DB_HOST') . ";dbname=$base;charset=utf8mb4",
            getenv('DB_USER'),
            getenv('DB_PASS'),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Reconstruit tous les index, application et démonstration.
 *
 * Extraite du corps du script pour être appelable depuis la section
 * d'administration (actions/admin/reindexer.php) : les deux voies passent par
 * exactement le même code, il n'y a pas deux façons d'indexer.
 *
 * @return array{rapport:string, musiques:int, artistes:int}
 */
function reindexerTout(Client $client): array
{
    $sqlMusiques = "SELECT id AS id_music, title AS title_music FROM tracks";
    $sqlArtistes = "SELECT id AS id_artist, name AS name_artist FROM artists";

    $lignes = [];

    // --- Index de l'application ---
    $pdo = Config::getConnection();
    $nbMusiques = indexer($client, $pdo, 'musiques', 'id_music', $sqlMusiques, ['title_music']);
    $nbArtistes = indexer($client, $pdo, 'artists', 'id_artist', $sqlArtistes, ['name_artist']);

    $lignes[] = "Indexation terminée ! $nbMusiques musiques et $nbArtistes artistes envoyés.";

    // --- Index de démonstration (silencieux si la base n'a pas été installée) ---
    $baseDemo = getenv('DB_NAME_DEMO') ?: getenv('DB_NAME') . '_demo';
    $pdoDemo = connexionVers($baseDemo);

    if ($pdoDemo === null) {
        $lignes[] = "Base de démonstration « $baseDemo » absente : index _demo ignorés.";
    } else {
        $nbDemoMusiques = indexer($client, $pdoDemo, 'musiques_demo', 'id_music', $sqlMusiques, ['title_music']);
        $nbDemoArtistes = indexer($client, $pdoDemo, 'artists_demo', 'id_artist', $sqlArtistes, ['name_artist']);
        $lignes[] = "Démonstration indexée ! $nbDemoMusiques musiques et $nbDemoArtistes artistes envoyés.";
    }

    return [
        'rapport'  => implode("\n", $lignes),
        'musiques' => $nbMusiques,
        'artistes' => $nbArtistes,
    ];
}

// --------------------------------------------------------- POINT D'ENTRÉE CLI
// Lancé par /init.sh au démarrage du conteneur. Un include depuis une action
// web ne déclenche rien : c'est l'appel à reindexerTout() qui indexe.

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    echo reindexerTout($client)['rapport'] . "\n";
}
