<?php
session_start();

require '../../vendor/autoload.php';
use Meilisearch\Client;

if (
    !isset($_POST['token'], $_SESSION['token']) ||
    $_POST['token'] !== $_SESSION['token']
) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Token invalide']);
    exit;
}
unset($_SESSION['token']);

$title     = trim(filter_input(INPUT_POST, 'title',     FILTER_DEFAULT));
$artist    = trim(filter_input(INPUT_POST, 'artist',    FILTER_DEFAULT));
$duration  = trim(filter_input(INPUT_POST, 'duration',  FILTER_DEFAULT));
$url       = filter_input(INPUT_POST, 'url', FILTER_VALIDATE_URL);
$miniature = filter_input(INPUT_POST, 'miniature', FILTER_VALIDATE_URL);

// Vérifie que les champs obligatoires sont présents
if (!$title || !$artist || !$url) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Champs obligatoires manquants']);
    exit;
}

// Extraction de l'ID YouTube depuis l'URL pour nommer le fichier de façon sûre
parse_str(parse_url($url, PHP_URL_QUERY), $params);
$video_id = $params['v'] ?? basename(parse_url($url, PHP_URL_PATH)); // fallback pour les URLs courtes
if (empty($video_id)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'URL invalide']);
    exit;
}

$output_path = "/var/www/music_data/%(id)s.%(ext)s";
$file = $video_id . ".wav"; // correspond maintenant au vrai fichier créé par yt-dlp
$safe_url = escapeshellarg($url);
$cmd = "/usr/local/bin/yt-dlp -x --audio-format wav --audio-quality 0 --add-metadata --no-overwrites -o " . escapeshellarg($output_path) . " " . $safe_url . " 2>&1";
exec($cmd, $output, $code);

$wav_path = "/var/www/music_data/" . $file;
if ($code !== 0 || !file_exists($wav_path)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "Erreur lors de l'import"]);
    exit;
}

include_once "../includes/config.php";
$pdo = Config::getConnection();

$req = $pdo->prepare("INSERT INTO tracks (title, duration, file, url, img, `added-by_id`) VALUES (:title, :duration, :file, :url, :img, :user)");
$req->execute([':title' => $title, ':duration' => $duration, ':file' => $file, ':url' => $url, ':img' => $miniature, ':user' => $_SESSION['user']['id']]);

$track_id = intval($pdo->lastInsertId());

try {
    $meiliKey = getenv('MS_PASS') ?? null;
    $client   = new Client('http://ms:7700', $meiliKey);

    $client->index('musiques')->addDocuments([[
        'id_music'    => $track_id,
        'title_music' => $title,
    ]]);

    $artists = explode(",", $artist);
    foreach ($artists as $art) {
        $art = trim($art);

        $req = $pdo->prepare("SELECT id FROM artists WHERE name = :name");
        $req->execute([':name' => $art]);
        $artistData = $req->fetch(PDO::FETCH_ASSOC);

        if ($artistData === false) {
            $req = $pdo->prepare("INSERT INTO artists (name) VALUES (:name)");
            $req->execute([':name' => $art]);
            $artist_id = intval($pdo->lastInsertId());

            $client->index('artists')->addDocuments([[
                'id_artist'   => $artist_id,
                'name_artist' => $art,
            ]]);
        } else {
            $artist_id = intval($artistData['id']);
        }

        $req = $pdo->prepare("INSERT INTO artist__track (artist_id, track_id) VALUES (:artist_id, :track_id)");
        $req->execute([':artist_id' => $artist_id, ':track_id' => $track_id]);
    }
} catch (\Exception $e) {
    // Meili est down ou a planté, l'import BDD est déjà fait
    error_log('Meilisearch error: ' . $e->getMessage());
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Importé avec succès', 'track_id' => $track_id]);
exit;