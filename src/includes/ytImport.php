<?php
/**
 * Logique d'import de titres YouTube, factorisée pour être partagée entre
 * l'import unitaire (avec confirmation) et l'import en masse.
 *
 *  - extractYtMetadata()  : récupère les métadonnées d'une URL via yt-dlp
 *  - importTrackFromUrl() : télécharge le WAV et insère le titre + relations
 */

require_once __DIR__ . '/artistImage.php';

/**
 * Récupère les métadonnées d'une vidéo YouTube via yt-dlp.
 * Renvoie null si l'extraction échoue, sinon un tableau associatif :
 * [title, artist, album, genre, duration, miniature].
 */
function extractYtMetadata(string $url): ?array
{
    $cmd = "yt-dlp --skip-download --no-playlist --dump-json " . escapeshellarg($url);
    $json = shell_exec($cmd);
    if ($json === null || trim($json) === '') {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }

    // yt-dlp ne renseigne 'track'/'artist' que pour de rares vidéos disposant
    // d'un encart Content ID. Pour l'immense majorité des imports (y compris
    // depuis music.youtube.com), on retombe sur la convention "Artiste - Titre"
    // du titre de la vidéo, puis sur le nom de la chaîne.
    $trackTitle  = $data['track'] ?? null;
    $trackArtist = $data['artist'] ?? null;
    if (!$trackArtist && !empty($data['artists']) && is_array($data['artists'])) {
        $trackArtist = implode(', ', $data['artists']);
    }
    if (!$trackArtist && !empty($data['creators']) && is_array($data['creators'])) {
        $trackArtist = implode(', ', $data['creators']);
    }

    if (!$trackTitle || !$trackArtist) {
        $videoTitle = $data['fulltitle'] ?? $data['title'] ?? '';
        $cleanTitle = preg_replace(
            '/\s*[\(\[][^\)\]]*(official|lyric|audio|video|visualizer|mv|remaster|hd|4k)[^\)\]]*[\)\]]\s*/i',
            ' ',
            $videoTitle
        );
        $cleanTitle = trim(preg_replace('/\s+/', ' ', $cleanTitle));

        if (preg_match('/^(.+?)\s+[-–—]\s+(.+)$/', $cleanTitle, $m)) {
            if (!$trackArtist) { $trackArtist = trim($m[1]); }
            if (!$trackTitle)  { $trackTitle  = trim($m[2]); }
        } elseif (!$trackTitle) {
            $trackTitle = $cleanTitle;
        }
    }

    if (!$trackArtist) {
        $channelName = $data['channel'] ?? $data['uploader'] ?? '';
        $trackArtist = preg_replace('/\s*-\s*Topic$/i', '', $channelName) ?: null;
    }

    // Le genre est parfois fourni par yt-dlp (tableau "genres" ou chaîne "genre")
    $genreBrut = $data['genres'] ?? $data['genre'] ?? '';
    if (is_array($genreBrut)) { $genreBrut = implode(', ', $genreBrut); }

    $thumb = '';
    if (!empty($data['thumbnails']) && is_array($data['thumbnails'])) {
        $thumb = $data['thumbnails'][count($data['thumbnails']) - 1]['url'] ?? '';
    }

    return [
        'title'     => mb_substr($trackTitle  ?: 'Aucun titre',   0, 50),
        'artist'    => $trackArtist ?: 'Aucun artiste',
        'album'     => $data['album'] ?? 'Aucun album',
        'genre'     => $genreBrut,
        'duration'  => intval($data['duration'] ?? 0),
        'miniature' => $thumb,
    ];
}

/**
 * Télécharge le titre et l'insère en base avec ses artistes et genres.
 * $meta doit contenir : title, artist, duration, miniature, genre (facultatif).
 * Renvoie ['success' => bool, 'message' => string, 'track_id' => int|null,
 *          'is_new' => bool, 'title' => string, 'artist' => string].
 */
function importTrackFromUrl(PDO $pdo, string $url, array $meta, int $userId): array
{
    // Les conversions WAV peuvent être longues : on lève la limite de temps
    if (function_exists('set_time_limit')) { @set_time_limit(600); }

    $title     = trim($meta['title'] ?? '');
    $artist    = trim($meta['artist'] ?? '');
    $duration  = intval($meta['duration'] ?? 0);
    $miniature = $meta['miniature'] ?? '';
    $genre     = trim($meta['genre'] ?? '');

    $result = ['success' => false, 'message' => '', 'track_id' => null,
               'is_new' => false, 'title' => $title, 'artist' => $artist];

    if (!$title || !$artist || !$url) {
        $result['message'] = 'Métadonnées incomplètes';
        return $result;
    }

    // Identifiant de la vidéo → nom de fichier
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);
    $video_id = $params['v'] ?? basename(parse_url($url, PHP_URL_PATH) ?? '');
    if (empty($video_id)) {
        $result['message'] = 'URL invalide';
        return $result;
    }

    $output_path = "/var/www/music_data/%(id)s.%(ext)s";
    $file        = $video_id . ".wav";
    $wav_path    = "/var/www/music_data/" . $file;

    // Téléchargement + conversion WAV si le fichier n'existe pas déjà
    if (!file_exists($wav_path)) {
        $cmd = "/usr/local/bin/yt-dlp -x --audio-format wav --audio-quality 0 --add-metadata --no-overwrites -o "
             . escapeshellarg($output_path) . " " . escapeshellarg($url) . " 2>&1";
        exec($cmd, $output, $code);

        if ($code !== 0 || !file_exists($wav_path)) {
            error_log('ytImport download failed: ' . implode(' | ', array_slice($output, -3)));
            $result['message'] = "Erreur lors du téléchargement";
            return $result;
        }
    }

    // Insertion du titre (ou récupération s'il existe déjà)
    $req = $pdo->prepare("SELECT id FROM tracks WHERE url = :url OR file = :file LIMIT 1");
    $req->execute([':url' => $url, ':file' => $file]);
    $existing = $req->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $track_id = intval($existing['id']);
        $is_new = false;
    } else {
        $req = $pdo->prepare("INSERT INTO tracks (title, duration, file, url, img, `added-by_id`) VALUES (:title, :duration, :file, :url, :img, :user)");
        $req->execute([':title' => $title, ':duration' => $duration, ':file' => $file,
                       ':url' => $url, ':img' => $miniature, ':user' => $userId]);
        $track_id = intval($pdo->lastInsertId());
        $is_new = true;
    }

    $result['track_id'] = $track_id;
    $result['is_new'] = $is_new;

    // Meilisearch (non bloquant) + artistes + genres
    $meiliClient = null;
    try {
        if (class_exists('Meilisearch\\Client')) {
            $meiliClient = new Meilisearch\Client('http://ms:7700', getenv('MS_PASS') ?: null);
            if ($is_new) {
                $meiliClient->index('musiques')->addDocuments([[
                    'id_music'    => $track_id,
                    'title_music' => $title,
                ]]);
            }
        }
    } catch (\Exception $e) {
        error_log('Meilisearch error: ' . $e->getMessage());
    }

    // Artistes
    $artistIds = [];
    foreach (explode(',', $artist) as $art) {
        $art = trim($art);
        if ($art === '') continue;

        $req = $pdo->prepare("SELECT id FROM artists WHERE name = :name");
        $req->execute([':name' => $art]);
        $artistData = $req->fetch(PDO::FETCH_ASSOC);

        if ($artistData === false) {
            $req = $pdo->prepare("INSERT INTO artists (name) VALUES (:name)");
            $req->execute([':name' => $art]);
            $artist_id = intval($pdo->lastInsertId());

            $artistImg = fetchArtistImage($art);
            if ($artistImg) {
                $req = $pdo->prepare("UPDATE artists SET img = :img WHERE id = :id");
                $req->execute([':img' => $artistImg, ':id' => $artist_id]);
            }

            if ($meiliClient && $is_new) {
                try {
                    $meiliClient->index('artists')->addDocuments([[
                        'id_artist'   => $artist_id,
                        'name_artist' => $art,
                    ]]);
                } catch (\Exception $e) {
                    error_log('Meilisearch artist error: ' . $e->getMessage());
                }
            }
        } else {
            $artist_id = intval($artistData['id']);
        }

        $artistIds[] = $artist_id;

        $req = $pdo->prepare("SELECT COUNT(*) FROM artist__track WHERE artist_id = :artist AND track_id = :track");
        $req->execute([':artist' => $artist_id, ':track' => $track_id]);
        if ($req->fetchColumn() == 0) {
            $req = $pdo->prepare("INSERT INTO artist__track (artist_id, track_id) VALUES (:artist_id, :track_id)");
            $req->execute([':artist_id' => $artist_id, ':track_id' => $track_id]);
        }
    }

    // Genres du titre (facultatifs)
    if ($genre !== '') {
        foreach (explode(',', $genre) as $g) {
            $g = mb_substr(trim($g), 0, 50);
            if ($g === '') continue;

            $req = $pdo->prepare("SELECT id FROM genres WHERE name = :name");
            $req->execute([':name' => $g]);
            $genreData = $req->fetch(PDO::FETCH_ASSOC);

            if ($genreData === false) {
                $req = $pdo->prepare("INSERT INTO genres (name) VALUES (:name)");
                $req->execute([':name' => $g]);
                $genre_id = intval($pdo->lastInsertId());
            } else {
                $genre_id = intval($genreData['id']);
            }

            $req = $pdo->prepare("SELECT COUNT(*) FROM track__genre WHERE track_id = :track AND genre_id = :genre");
            $req->execute([':track' => $track_id, ':genre' => $genre_id]);
            if ($req->fetchColumn() == 0) {
                $req = $pdo->prepare("INSERT INTO track__genre (track_id, genre_id) VALUES (:track_id, :genre_id)");
                $req->execute([':track_id' => $track_id, ':genre_id' => $genre_id]);
            }

            foreach ($artistIds as $artist_id) {
                $req = $pdo->prepare("SELECT COUNT(*) FROM artist__genre WHERE artist_id = :artist AND genre_id = :genre");
                $req->execute([':artist' => $artist_id, ':genre' => $genre_id]);
                if ($req->fetchColumn() == 0) {
                    $req = $pdo->prepare("INSERT INTO artist__genre (artist_id, genre_id) VALUES (:artist_id, :genre_id)");
                    $req->execute([':artist_id' => $artist_id, ':genre_id' => $genre_id]);
                }
            }
        }
    }

    $result['success'] = true;
    $result['message'] = $is_new ? 'Importé' : 'Déjà présent';
    return $result;
}
