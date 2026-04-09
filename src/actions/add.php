<?php
session_start();

if (
    !isset($_POST['token'], $_SESSION['token']) ||
    $_POST['token'] !== $_SESSION['token']
) {
    die('Token invalide');
}

$title = filter_input(INPUT_POST, 'title', FILTER_DEFAULT);
$artist = filter_input(INPUT_POST, 'artist', FILTER_DEFAULT);
$duration = filter_input(INPUT_POST, 'duration', FILTER_DEFAULT);
$url = filter_input(INPUT_POST, 'url', FILTER_DEFAULT);
$miniature = filter_input(INPUT_POST, 'miniature', FILTER_DEFAULT);
$output_path = "/var/www/music_data/%(title)s.%(ext)s";
$file = $title.".wav";
$safe_url = escapeshellarg($url);
$cmd = "/usr/local/bin/yt-dlp -x --audio-format wav --audio-quality 0 --add-metadata --no-overwrites -o " . escapeshellarg($output_path) . " " . $safe_url . " 2>&1";

exec($cmd, $output, $code);

if ($code !== 0) {
    http_response_code(500);
    echo "<pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
    exit;
}

include_once "../includes/config.php";
$pdo = Config::getConnection();

$req = $pdo->prepare("INSERT INTO tracks (title, duration, file, url, img, `added-by_id`) VALUES (:title, :duration, :file, :url, :img, :user)");
$req->bindParam(':title', $title);
$req->bindParam(':duration', $duration);
$req->bindParam(':file', $file);
$req->bindParam(':url', $url);
$req->bindParam(':img', $miniature);
$req->bindParam(':user', $_SESSION['user']['id']);
$req->execute();

$track_id = intval($pdo->lastInsertId());


require '../../vendor/autoload.php';
use Meilisearch\Client;

$meiliKey = getenv('MS_PASS') ?? null;
$client = new Client('http://ms:7700', $meiliKey);

$client->index('musiques')->addDocuments([[
    'id' => $track_id,
    'title' => $title,
]]);

$req = $pdo->prepare("SELECT id FROM artists WHERE name = :name");
$req->bindParam(':name', $artist);
$req->execute();

$artistData = $req->fetch(PDO::FETCH_ASSOC);

if ($artistData === false)
{
    $req = $pdo->prepare("INSERT INTO artists (name) VALUES (:name)");
    $req->bindParam(':name', $artist);
    $req->execute();

    $artist_id = intval($pdo->lastInsertId());
}
else
{
    $artist_id = intval($artistData["id"]);
}

$req = $pdo->prepare("INSERT INTO artist__track (artist_id, track_id) VALUES (:artist_id, :track_id)");
$req->bindParam(':artist_id', $artist_id);
$req->bindParam(':track_id', $track_id);
$req->execute();

$client->index('artists')->addDocuments([[
    'id' => $artist_id,
    'name' => $artist,
]]);

header("Location: ../index.php");