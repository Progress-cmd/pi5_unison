<?php
/**
 * Développe une liste d'URLs (une par ligne) en la liste plate des vidéos
 * à importer. Une ligne peut être une vidéo unique ou une playlist YouTube,
 * auquel cas elle est développée en ses titres individuels.
 *
 * Entrée POST : text (plusieurs lignes)
 * Sortie JSON : { success, tracks: [{url, title}], count, echecs: [{lien, raison}] }
 *
 * Une ligne qui ne peut pas être développée n'est jamais silencieusement
 * ignorée : elle ressort dans `echecs` avec sa raison, pour que l'interface
 * puisse la signaler. C'est ce qui manquait, et qui faisait disparaître un
 * lien de la liste sans que rien ne l'indique.
 */
include_once "../includes/auth.php";
exigerConnexion(true);
refuserSiDemo(true);
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Non connecté']);
    exit;
}

// L'analyse des liens (yt-dlp) peut prendre plusieurs secondes ; on libère
// le verrou de session pour ne pas bloquer la navigation ni la lecture.
session_write_close();

@set_time_limit(120);

require_once '../includes/ytImport.php';

$text = filter_input(INPUT_POST, 'text', FILTER_DEFAULT) ?? '';
$lines = preg_split('/\r\n|\r|\n/', trim($text));

$tracks = [];
$echecs = [];
$seen = [];
$MAX = 300; // garde-fou

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    // Une ligne non vide qui n'est pas une URL est une erreur de saisie :
    // on la signale plutôt que de l'ignorer.
    if (!preg_match('#^https?://#i', $line)) {
        $echecs[] = ['lien' => mb_substr($line, 0, 120), 'raison' => "Ce n'est pas un lien"];
        continue;
    }

    // Une page "playlist" est développée ; un lien de vidéo (même avec &list=)
    // ne prend que la vidéo.
    $isPlaylist = (strpos($line, '/playlist') !== false)
        || (strpos($line, 'list=') !== false && strpos($line, 'v=') === false);
    $scope = $isPlaylist ? ['--flat-playlist'] : ['--no-playlist', '--flat-playlist'];

    [$output, $erreurs, $code] = executerYtDlp(array_merge($scope, ['--dump-json', $line]));

    if (trim($output) === '') {
        $echecs[] = ['lien' => $line, 'raison' => traduireErreurYtDlp($erreurs)];
        error_log("import_expand KO ($line) : " . trim($erreurs));
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

echo json_encode([
    'success' => true,
    'tracks'  => $tracks,
    'count'   => count($tracks),
    'echecs'  => $echecs,
]);
