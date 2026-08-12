<?php
include_once "../includes/auth.php";
include_once "../includes/config.php";

// 1. VERIFICATION DE SECURITE
exigerConnexion(false);

// 2. RECUPERATION ET NETTOYAGE DU NOM DE FICHIER
$file = $_GET['file'] ?? '';

// SECURITE CRITIQUE : Empêcher l'attaquant de sortir du dossier avec des ../../
$file = basename($file);

/*
 * Le dossier dépend de la session : en démonstration c'est music_data/demo/,
 * qui ne contient que des morceaux libres de droits. Un nom de fichier
 * personnel n'y existe pas, donc la diffusion publique du catalogue privé est
 * impossible et pas seulement interdite.
 */
$base_path = Config::cheminMusiques();

// La session n'est plus nécessaire : on la libère avant le streaming, qui est long.
session_write_close();

$full_path = $base_path . $file;

// 3. VERIFICATION DE L'EXISTENCE
if (empty($file) || !file_exists($full_path)) {
    header("HTTP/1.1 404 Not Found");
    exit("Fichier introuvable.");
}

// 4. ENVOI DU FICHIER AU NAVIGATEUR
$size = filesize($full_path);
$start = 0;
$end = $size - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches);
    $start = (int)$matches[1];
    $end = isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : $size - 1;

    header("HTTP/1.1 206 Partial Content");
    header("Content-Range: bytes $start-$end/$size");
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . ($end - $start + 1));
header('Accept-Ranges: bytes');

$fp = fopen($full_path, 'rb');
fseek($fp, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($fp)) {
    $chunk = fread($fp, min(8192, $remaining));
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($fp);
exit;