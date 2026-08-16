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
 * Lance yt-dlp en séparant la sortie standard de la sortie d'erreur.
 *
 * shell_exec() jette la sortie d'erreur : c'est elle qui porte la raison d'un
 * échec (âge, vidéo privée, blocage géographique…). Sans elle, un import raté
 * est indiscernable d'un import vide.
 *
 * La séparation se fait par redirection vers un fichier temporaire, et non par
 * proc_open() : cette fonction figure dans le disable_functions de la
 * configuration de production (docker/security.ini), où elle provoquerait une
 * erreur fatale à chaque import. exec() y est autorisé, et /tmp fait partie de
 * l'open_basedir.
 *
 * @return array{0:string,1:string,2:int} [sortie, erreurs, code de retour]
 */
/**
 * Options ajoutées à tous les appels yt-dlp.
 *
 * Un import en masse enchaîne les requêtes vers YouTube, qui finit par
 * répondre « HTTP 429 : Too Many Requests ». Les échecs semblent alors
 * aléatoires, et une relance manuelle en récupère quelques-uns à chaque
 * passage — le symptôme classique d'une limitation de débit.
 *
 * Ces options laissent yt-dlp espacer et réessayer lui-même, ce qui évite
 * l'essentiel des refus sans rien changer au reste :
 *   --sleep-requests    pause entre deux requêtes d'extraction
 *   --retries           reprises sur erreur HTTP, avec attente croissante
 *   --extractor-retries reprises sur erreur d'extraction
 */
const YTDLP_OPTIONS_COMMUNES = [
    '--sleep-requests', '1',
    '--retries', '5',
    '--extractor-retries', '3',
    '--no-warnings',
];

function executerYtDlp(array $arguments): array
{
    $arguments = array_merge(YTDLP_OPTIONS_COMMUNES, $arguments);

    $cmd = '/usr/local/bin/yt-dlp ' . implode(' ', array_map('escapeshellarg', $arguments));

    $fichierErreurs = tempnam('/tmp', 'ytdlp_');
    if ($fichierErreurs === false) {
        return ['', "Impossible de préparer la sortie d'erreur de yt-dlp", -1];
    }

    $lignes = [];
    exec($cmd . ' 2>' . escapeshellarg($fichierErreurs), $lignes, $code);

    $erreurs = (string) @file_get_contents($fichierErreurs);
    @unlink($fichierErreurs);

    return [implode("\n", $lignes), $erreurs, $code];
}

/**
 * Traduit la sortie d'erreur de yt-dlp en une raison lisible.
 *
 * Le but est qu'un échec dise *pourquoi* : « vérification d'âge » est
 * actionnable, « erreur lors du téléchargement » ne l'est pas.
 */
function traduireErreurYtDlp(string $erreurs): string
{
    $motifs = [
        '/sign in to confirm your age|age.?restricted|inappropriate for some users/i'
            => "YouTube exige une vérification d'âge pour cette vidéo",
        '/private video/i'
            => 'Vidéo privée',
        '/members[- ]only|join this channel/i'
            => 'Réservée aux membres de la chaîne',
        '/removed by the uploader|account associated .* has been terminated/i'
            => 'Vidéo supprimée par son auteur',
        /*
         * « not available on this app » n'est PAS une vidéo indisponible : la
         * vidéo existe, c'est le client simulé par yt-dlp que YouTube refuse.
         * À tester avant le motif générique ci-dessous, qui contient
         * « is not available » et le capterait sinon — en donnant une raison
         * fausse et une action inutile.
         */
        '/not available on this app|player response|failed to extract/i'
            => 'YouTube a changé son format : yt-dlp doit être mis à jour (unison ytdlp)',

        '/video unavailable|is not available/i'
            => 'Vidéo indisponible',
        '/not available in your country|geo.?restricted|blocked it in your country/i'
            => 'Vidéo bloquée dans ce pays',
        '/copyright/i'
            => "Retirée pour motif de droits d'auteur",
        '/this live event will begin|premieres in/i'
            => "La diffusion n'a pas encore commencé",
        '/sign in to confirm.*not a bot|confirm you.?re not a bot/i'
            => 'YouTube demande une confirmation anti-robot',

        /*
         * Limitation de débit. À placer AVANT le motif réseau ci-dessous :
         * le message de yt-dlp est « unable to download video data: HTTP Error
         * 429 », qui contient « unable to download » et se faisait donc
         * étiqueter « échec réseau » — un diagnostic trompeur, puisque le
         * réseau va très bien. C'est le cas typique de l'import en masse :
         * YouTube coupe après quelques titres, et l'attente suffit à repartir.
         */
        '/http error 429|too many requests|rate.?limit|throttl/i'
            => 'YouTube limite les téléchargements (trop de requêtes d\'affilée) — '
             . 'patientez quelques minutes avant de relancer',

        // 403 sur YouTube est presque toujours transitoire (jeton expiré,
        // protection anti-robot), pas une vraie interdiction d'accès.
        '/http error 403|forbidden/i'
            => 'YouTube a refusé le téléchargement (403) — généralement temporaire, réessayez',

        '/unable to download|failed to resolve|connection|timed out|temporary failure/i'
            => 'Échec réseau pendant la récupération',
        '/unsupported url|is not a valid url/i'
            => 'Lien non reconnu par yt-dlp',
    ];

    foreach ($motifs as $motif => $raison) {
        if (preg_match($motif, $erreurs)) {
            return $raison;
        }
    }

    // Aucun motif connu : on remonte la première ligne ERROR telle quelle,
    // tronquée, plutôt qu'un message générique qui n'apprend rien.
    if (preg_match('/^ERROR:\s*(.+)$/mi', $erreurs, $m)) {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $m[1])), 0, 160);
    }

    return 'Raison inconnue (voir les logs du conteneur)';
}

/**
 * Choisit la miniature à enregistrer parmi celles que propose yt-dlp.
 *
 * On prenait aveuglément la dernière (la plus grande). Deux écueils : elle
 * n'existe pas pour toutes les vidéos, et son URL peut dépasser les 250
 * caractères de la colonne `tracks.img`, où elle serait tronquée. Dans les
 * deux cas le résultat est une image cassée dans l'interface.
 *
 * On descend donc de la meilleure à la moins bonne jusqu'à en trouver une qui
 * tienne dans la colonne et qui réponde réellement.
 */
function choisirMiniature(array $data, int $maxEssais = 3): string
{
    $candidates = [];

    if (!empty($data['thumbnails']) && is_array($data['thumbnails'])) {
        // yt-dlp classe de la moins bonne à la meilleure : on inverse.
        foreach (array_reverse($data['thumbnails']) as $t) {
            if (!empty($t['url'])) {
                $candidates[] = $t['url'];
            }
        }
    }

    // Repli garanti : hqdefault existe pour toute vidéo YouTube.
    if (!empty($data['id'])) {
        $candidates[] = 'https://i.ytimg.com/vi/' . $data['id'] . '/hqdefault.jpg';
    }

    $essais = 0;
    foreach ($candidates as $url) {
        if (mb_strlen($url) > 250) {
            continue; // serait tronquée en base
        }
        if (++$essais > $maxEssais) {
            break;
        }
        if (urlImageValide($url)) {
            return $url;
        }
    }

    return '';
}

/**
 * Récupère les métadonnées d'une vidéo YouTube via yt-dlp.
 * Renvoie null si l'extraction échoue, sinon un tableau associatif :
 * [title, artist, album, genre, duration, miniature].
 *
 * @param string|null $raison Reçoit la raison de l'échec le cas échéant.
 */
function extractYtMetadata(string $url, ?string &$raison = null): ?array
{
    [$json, $erreurs, $code] = executerYtDlp(['--skip-download', '--no-playlist', '--dump-json', $url]);

    if (trim($json) === '') {
        $raison = traduireErreurYtDlp($erreurs);
        error_log("yt-dlp metadata KO ($url) : " . trim($erreurs));
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        $raison = 'Réponse illisible de yt-dlp';
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

    $thumb = choisirMiniature($data);

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
        /*
         * Pause aléatoire de 1 à 5 s avant le téléchargement lui-même : c'est
         * l'enchaînement régulier des téléchargements qui déclenche le plus
         * sûrement la limitation. Le coût est négligeable devant la conversion
         * WAV qui suit.
         */
        [, $erreurs, $code] = executerYtDlp([
            '-x', '--audio-format', 'wav', '--audio-quality', '0',
            '--sleep-interval', '1', '--max-sleep-interval', '5',
            '--add-metadata', '--no-overwrites', '-o', $output_path, $url,
        ]);

        if ($code !== 0 || !file_exists($wav_path)) {
            error_log("yt-dlp download KO ($url) : " . trim($erreurs));
            // La raison exacte remonte jusqu'à l'interface, pas seulement aux logs.
            $result['message'] = traduireErreurYtDlp($erreurs);
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
