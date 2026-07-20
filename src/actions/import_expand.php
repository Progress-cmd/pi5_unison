<?php
/**
 * Développe une liste d'URLs (une par ligne) en la liste plate des vidéos
 * à importer. Une ligne peut être une vidéo unique ou une playlist YouTube,
 * auquel cas elle est développée en ses titres individuels.
 *
 * Entrée POST : text (plusieurs lignes)
 * Sortie JSON : { success, tracks: [{url, title}], count }
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Non connecté']);
    exit;
}

@set_time_limit(120);

$text = filter_input(INPUT_POST, 'text', FILTER_DEFAULT) ?? '';
$lines = preg_split('/\r\n|\r|\n/', trim($text));

$tracks = [];
$seen = [];
$MAX = 300; // garde-fou

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || !preg_match('#^https?://#i', $line)) {
        continue;
    }

    // Une page "playlist" est développée ; un lien de vidéo (même avec &list=)
    // ne prend que la vidéo.
    $isPlaylist = (strpos($line, '/playlist') !== false)
        || (strpos($line, 'list=') !== false && strpos($line, 'v=') === false);
    $scopeFlag = $isPlaylist ? '--flat-playlist' : '--no-playlist --flat-playlist';

    $cmd = "yt-dlp {$scopeFlag} --dump-json " . escapeshellarg($line) . " 2>/dev/null";
    $output = shell_exec($cmd);
    if (!$output) {
        continue;
    }

    foreach (preg_split('/\r?\n/', trim($output)) as $jsonLine) {
        $jsonLine = trim($jsonLine);
        if ($jsonLine === '') continue;

        $d = json_decode($jsonLine, true);
        if (!is_array($d)) continue;

        $id = $d['id'] ?? null;
        if (!$id) continue;

        // URL canonique reconstruite depuis l'identifiant
        $videoUrl = "https://www.youtube.com/watch?v=" . $id;
        if (isset($seen[$id])) continue;
        $seen[$id] = true;

        $tracks[] = [
            'url'   => $videoUrl,
            'title' => $d['title'] ?? $videoUrl,
        ];

        if (count($tracks) >= $MAX) break 2;
    }
}

echo json_encode(['success' => true, 'tracks' => $tracks, 'count' => count($tracks)]);
