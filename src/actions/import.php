<?php
session_start();

require '../../vendor/autoload.php';
use Meilisearch\Client;

$log_file = '/tmp/import_debug.log';
function log_msg($m) { global $log_file; file_put_contents($log_file, date('H:i:s') . ' ' . $m . PHP_EOL, FILE_APPEND); }

log_msg("=== START ===");

if (
    !isset($_POST['token'], $_SESSION['token']) ||
    $_POST['token'] !== $_SESSION['token']
) {
    log_msg("Token fail");
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

log_msg("Input: title=$title | artist=$artist | url=$url");

if (!$title || !$artist || !$url) {
    log_msg("Missing fields");
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Champs obligatoires manquants']);
    exit;
}

parse_str(parse_url($url, PHP_URL_QUERY), $params);
$video_id = $params['v'] ?? basename(parse_url($url, PHP_URL_PATH));
if (empty($video_id)) {
    log_msg("Bad video_id");
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'URL invalide']);
    exit;
}

log_msg("video_id=$video_id");

$output_path = "/var/www/music_data/%(id)s.%(ext)s";
$file = $video_id . ".wav";
$wav_path = "/var/www/music_data/" . $file;

log_msg("wav_path=$wav_path | exists=" . (file_exists($wav_path) ? 'yes' : 'no'));

if (!file_exists($wav_path)) {
    log_msg("Starting download...");
    $safe_url = escapeshellarg($url);
    $cmd = "/usr/local/bin/yt-dlp -x --audio-format wav --audio-quality 0 --add-metadata --no-overwrites -o " . escapeshellarg($output_path) . " " . $safe_url . " 2>&1";
    
    log_msg("cmd=$cmd");
    
    exec($cmd, $output, $code);
    
    log_msg("code=$code | output_count=" . count($output));
    
    if ($code !== 0) {
        log_msg("ERROR: yt-dlp returned $code");
        log_msg("Last output: " . implode(" | ", array_slice($output, -5)));
    }

    if ($code !== 0 || !file_exists($wav_path)) {
        log_msg("FAIL: code=$code, exists=" . (file_exists($wav_path) ? 'yes' : 'no'));
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Erreur lors de l'import"]);
        exit;
    }
    log_msg("Download OK");
} else {
    log_msg("File already exists");
}

include_once "../includes/config.php";
$pdo = Config::getConnection();

$req = $pdo->prepare("SELECT id FROM tracks WHERE url = :url OR file = :file LIMIT 1");
$req->execute([':url' => $url, ':file' => $file]);
$existing = $req->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    log_msg("Track exists: id=" . $existing['id']);
    $track_id = intval($existing['id']);
    $is_new = false;
} else {
    log_msg("Inserting new track");
    $req = $pdo->prepare("INSERT INTO tracks (title, duration, file, url, img, `added-by_id`) VALUES (:title, :duration, :file, :url, :img, :user)");
    $req->execute([':title' => $title, ':duration' => $duration, ':file' => $file, ':url' => $url, ':img' => $miniature, ':user' => $_SESSION['user']['id']]);
    $track_id = intval($pdo->lastInsertId());
    $is_new = true;
    log_msg("Track inserted: id=$track_id");
}

try {
    $meiliKey = getenv('MS_PASS') ?? null;
    $client   = new Client('http://ms:7700', $meiliKey);

    if ($is_new) {
        log_msg("Adding to Meilisearch");
        $client->index('musiques')->addDocuments([[
            'id_music'    => $track_id,
            'title_music' => $title,
        ]]);
    }

    $artists = explode(",", $artist);
    foreach ($artists as $art) {
        $art = trim($art);
        if (empty($art)) continue;
        
        log_msg("Artist: $art");
        
        $req = $pdo->prepare("SELECT id FROM artists WHERE name = :name");
        $req->execute([':name' => $art]);
        $artistData = $req->fetch(PDO::FETCH_ASSOC);

        if ($artistData === false) {
            $req = $pdo->prepare("INSERT INTO artists (name) VALUES (:name)");
            $req->execute([':name' => $art]);
            $artist_id = intval($pdo->lastInsertId());

            if ($is_new) {
                $client->index('artists')->addDocuments([[
                    'id_artist'   => $artist_id,
                    'name_artist' => $art,
                ]]);
            }
        } else {
            $artist_id = intval($artistData['id']);
        }

        $req = $pdo->prepare("SELECT COUNT(*) FROM artist__track WHERE artist_id = :artist AND track_id = :track");
        $req->execute([':artist' => $artist_id, ':track' => $track_id]);
        if ($req->fetchColumn() == 0) {
            $req = $pdo->prepare("INSERT INTO artist__track (artist_id, track_id) VALUES (:artist_id, :track_id)");
            $req->execute([':artist_id' => $artist_id, ':track_id' => $track_id]);
        }
    }
} catch (\Exception $e) {
    log_msg("Meili error: " . $e->getMessage());
    error_log('Meilisearch error: ' . $e->getMessage());
}

log_msg("=== SUCCESS ===");
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => ($is_new ? 'Importé' : 'Déjà importé'), 'track_id' => $track_id]);
exit;
