<?php
/**
 * Récupère l'URL d'une photo d'artiste via l'API publique Deezer
 * (gratuite, sans clé). Renvoie null si aucun résultat n'est trouvé
 * ou en cas d'erreur réseau.
 *
 * L'appel passe par cURL et non par file_get_contents() : la configuration de
 * production (docker/security.ini) désactive allow_url_fopen, ce qui faisait
 * échouer silencieusement toutes les récupérations d'images en production —
 * les artistes y étaient créés sans photo, alors que tout fonctionnait en dev.
 */
function fetchArtistImage(string $name): ?string
{
    $name = trim($name);
    if ($name === '' || mb_strtolower($name) === 'aucun artiste') {
        return null;
    }

    $url = 'https://api.deezer.com/search/artist?limit=1&q=' . urlencode($name);
    $json = recupererUrl($url, 4);

    if ($json === null) {
        return null;
    }

    $data = json_decode($json, true);
    $image = $data['data'][0]['picture_medium'] ?? null;

    // La colonne artists.img est un varchar(500) : une URL plus longue serait
    // tronquée et donnerait une image cassée. Mieux vaut aucune image.
    return ($image && mb_strlen($image) <= 500) ? $image : null;
}

/**
 * Télécharge le contenu d'une URL. Renvoie null en cas d'échec.
 * Indépendant d'allow_url_fopen, donc valable en dev comme en production.
 */
function recupererUrl(string $url, int $timeout = 4): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_USERAGENT      => 'Unison/1.0',
    ]);

    $contenu = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erreur  = curl_error($ch);
    curl_close($ch);

    if ($contenu === false || $code < 200 || $code >= 300) {
        error_log("recupererUrl KO ($url) : " . ($erreur ?: "HTTP $code"));
        return null;
    }

    return $contenu;
}

/**
 * Vérifie qu'une URL d'image répond bien. Sert à écarter les miniatures
 * introuvables avant de les enregistrer, plutôt que de découvrir le problème
 * sous la forme d'une image cassée dans l'interface.
 */
function urlImageValide(?string $url, int $timeout = 3): bool
{
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL) || !function_exists('curl_init')) {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,   // requête HEAD : on ne télécharge pas l'image
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_USERAGENT      => 'Unison/1.0',
    ]);

    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}
