<?php
/**
 * Récupère l'URL d'une photo d'artiste via l'API publique Deezer
 * (gratuite, sans clé). Renvoie null si aucun résultat n'est trouvé
 * ou en cas d'erreur réseau.
 */
function fetchArtistImage(string $name): ?string
{
    $name = trim($name);
    if ($name === '' || mb_strtolower($name) === 'aucun artiste') {
        return null;
    }

    $url = 'https://api.deezer.com/search/artist?limit=1&q=' . urlencode($name);

    $context = stream_context_create(['http' => ['timeout' => 4]]);
    $json = @file_get_contents($url, false, $context);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    return $data['data'][0]['picture_medium'] ?? null;
}
