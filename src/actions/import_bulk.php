<?php
/**
 * Importe automatiquement un titre depuis une URL YouTube, sans étape de
 * confirmation (métadonnées déduites automatiquement). Appelé en boucle
 * par l'interface d'import multiple, une URL à la fois.
 *
 * Entrée POST : url
 * Sortie JSON : { success, message, title, artist, is_new }
 */
include_once "../includes/auth.php";
exigerConnexion(true);
verifierCsrf(true);
refuserSiDemo(true);
header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Non connecté']);
    exit;
}

// L'import est long (téléchargement + conversion WAV). On lit l'utilisateur
// puis on libère immédiatement le verrou de session, sinon toutes les autres
// requêtes (navigation, lecture audio) resteraient bloquées jusqu'à la fin.
$userId = (int) $_SESSION['user']['id'];
session_write_close();

require '../../vendor/autoload.php';
require_once '../includes/ytImport.php';
require_once '../includes/albums.php';
include_once '../includes/config.php';

@set_time_limit(600);

$url = filter_input(INPUT_POST, 'url', FILTER_VALIDATE_URL);
if (!$url) {
    echo json_encode(['success' => false, 'message' => 'URL invalide']);
    exit;
}

/*
 * Contexte d'album, transmis par la page quand la playlist développée en est
 * un. Facultatif : un import de titres isolés n'en envoie pas, et tout
 * continue de fonctionner comme avant.
 */
$albumTitre   = trim((string) (filter_input(INPUT_POST, 'album_titre', FILTER_DEFAULT) ?? ''));
$albumArtiste = trim((string) (filter_input(INPUT_POST, 'album_artiste', FILTER_DEFAULT) ?? ''));
$albumSource  = filter_input(INPUT_POST, 'album_source', FILTER_VALIDATE_URL) ?: null;
$albumPiste   = filter_input(INPUT_POST, 'album_piste', FILTER_VALIDATE_INT) ?: null;

$pdo = Config::getConnection();

/*
 * Titre déjà présent : on s'arrête ici.
 *
 * Le fichier n'était de toute façon pas retéléchargé — fichierExistantPour()
 * le réutilise — mais extractYtMetadata() interrogeait quand même YouTube pour
 * chaque titre, soit environ quatre secondes chacun. Sur un album de douze
 * titres déjà importés, c'était une minute d'attente pour ne rien faire, et
 * douze requêtes de plus vers YouTube, qui les compte.
 *
 * Le rattachement à l'album, lui, reste effectué : c'est précisément le cas
 * du « je recolle le lien d'OK Computer pour créer l'album ».
 */
$existant = titreDejaImporte($pdo, $url);

if ($existant !== null) {
    $albumId = null;

    if ($albumTitre !== '') {
        try {
            $albumId = albumCreerOuRetrouver($pdo, $albumTitre, $albumArtiste ?: null, $albumSource, $userId);
            // estSource = false : ce titre existait avant cet album, il ne
            // doit pas disparaître avec lui.
            albumRattacherTitre($pdo, (int) $existant['id'], $albumId, false, $albumPiste);
        } catch (Throwable $e) {
            journalErreur('contenu', 'album_rattachement_echoue',
                "Rattachement à l'album impossible : " . $albumTitre,
                ['track_id' => $existant['id'], 'erreur' => $e->getMessage()]);
        }
    }

    echo json_encode([
        'success'  => true,
        'message'  => 'Déjà en base',
        'title'    => $existant['title'],
        'artist'   => '',
        'is_new'   => false,
        'existant' => true,
        'url'      => $url,
        'album_id' => $albumId,
    ]);
    exit;
}

$raison = null;
$meta = extractYtMetadata($url, $raison);
if ($meta === null) {
    echo json_encode([
        'success' => false,
        'message' => $raison ?: 'Métadonnées introuvables',
        'url'     => $url,
    ]);
    exit;
}

$res = importTrackFromUrl($pdo, $url, $meta, $userId);

/*
 * Rattachement à l'album, une fois le titre en base.
 *
 * `is_new` est ce qui distingue les deux situations : un titre que CET import
 * vient de créer est « entré par l'album » et pourra être supprimé avec lui ;
 * un titre déjà présent est simplement rattaché, et survivra à la suppression.
 * C'est toute la garantie demandée, et elle tient à ce booléen.
 */
$albumId = null;
if ($res['success'] && $res['track_id'] && $albumTitre !== '') {
    try {
        $albumId = albumCreerOuRetrouver($pdo, $albumTitre, $albumArtiste ?: null, $albumSource, $userId);
        albumRattacherTitre($pdo, (int) $res['track_id'], $albumId, (bool) $res['is_new'], $albumPiste);

        // L'année et la pochette n'existent qu'au niveau du titre : on en
        // profite pour compléter l'album, sans écraser ce qui est déjà posé.
        albumCompleter($pdo, $albumId, $meta['annee'] ?? null, $meta['miniature'] ?? null);
    } catch (Throwable $e) {
        // Un rattachement raté ne doit pas faire échouer un import réussi :
        // le titre est en base et lisible, il manque seulement son album.
        journalErreur('contenu', 'album_rattachement_echoue',
            "Rattachement à l'album impossible : " . $albumTitre,
            ['track_id' => $res['track_id'], 'erreur' => $e->getMessage()]);
    }
}

echo json_encode([
    'success'  => $res['success'],
    'message'  => $res['message'],
    'title'    => $res['title'],
    'artist'   => $res['artist'],
    'is_new'   => $res['is_new'],
    'url'      => $url,
    'album_id' => $albumId,
]);
