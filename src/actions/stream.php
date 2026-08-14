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

/*
 * 4. TYPE DE CONTENU
 *
 * Il était fixé à « audio/mpeg » pour tout le monde, alors que les imports
 * produisent des .wav. Annoncer un type faux n'est pas anodin : la réponse
 * porte aussi « X-Content-Type-Options: nosniff » (voir docker/000-default.conf),
 * qui interdit justement au navigateur de rattraper l'erreur en devinant. Le
 * lecteur peut alors échouer à déterminer la durée du morceau — et sans durée
 * connue, pas de barre de progression déplaçable dans la notification Android.
 */
const TYPES_AUDIO = [
    'wav'  => 'audio/wav',
    'mp3'  => 'audio/mpeg',
    'm4a'  => 'audio/mp4',
    'mp4'  => 'audio/mp4',
    'aac'  => 'audio/aac',
    'ogg'  => 'audio/ogg',
    'opus' => 'audio/ogg',
    'flac' => 'audio/flac',
    'webm' => 'audio/webm',
];

$extension = strtolower((string) pathinfo($full_path, PATHINFO_EXTENSION));
$type = TYPES_AUDIO[$extension] ?? 'application/octet-stream';

// 5. ENVOI DU FICHIER AU NAVIGATEUR
$size = filesize($full_path);
$start = 0;
$end = $size - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    /*
     * Les bornes reçues sont bornées au fichier. Sans ça, une demande au-delà
     * de la fin produisait un Content-Length supérieur à ce qui était réellement
     * envoyé : le navigateur attendait indéfiniment des octets qui ne venaient
     * pas. C'est exactement ce que fait Android quand on déplace le curseur
     * près de la fin d'un morceau.
     */
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
        $debutDemande = $matches[1];
        $finDemandee  = $matches[2];

        if ($debutDemande === '' && $finDemandee !== '') {
            // Forme suffixe « bytes=-500 » : les N derniers octets.
            $longueur = min((int) $finDemandee, $size);
            $start = $size - $longueur;
            $end   = $size - 1;
        } else {
            $start = (int) $debutDemande;
            $end   = $finDemandee !== '' ? (int) $finDemandee : $size - 1;
        }

        $end = min($end, $size - 1);

        // Intervalle inexploitable : la norme impose 416 et la taille réelle,
        // ce qui permet au lecteur de repartir sur de bonnes bases.
        if ($start < 0 || $start > $end) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header("Content-Range: bytes */$size");
            exit;
        }

        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $start-$end/$size");
    }
}

header('Content-Type: ' . $type);
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