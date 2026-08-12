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
    // Délais par défaut de recupererUrl() : ils tiennent compte d'un réseau
    // domestique lent, un import ne doit pas échouer pour une photo manquante.
    $json = recupererUrl($url);

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
 * Options cURL communes à tous les appels sortants.
 *
 * Deux réglages méritent une explication, tirés d'échecs constatés en
 * production sur Raspberry Pi alors que tout passait en développement :
 *
 *  - IPRESOLVE_V4 : sans lui, cURL demande d'abord un enregistrement AAAA.
 *    Sur un réseau sans IPv6 fonctionnel, cette requête n'obtient pas de
 *    réponse et attend l'expiration du délai — à chaque appel.
 *  - un délai de connexion distinct du délai total : la résolution DNS entre
 *    dans le premier. Quatre secondes suffisent sur une machine de bureau,
 *    pas derrière un résolveur domestique lent.
 */
function optionsCurl(int $connexion, int $total): array
{
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => $connexion,
        CURLOPT_TIMEOUT        => $total,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_USERAGENT      => 'Unison/1.0',
    ];
}

/**
 * Télécharge le contenu d'une URL. Renvoie null en cas d'échec.
 * Indépendant d'allow_url_fopen, donc valable en dev comme en production.
 *
 * Une seconde tentative est faite en cas d'échec : ces fonctions sont
 * appelées en lot sur des dizaines d'artistes, et un incident réseau
 * ponctuel ne doit pas faire renoncer définitivement sur celui-là.
 */
function recupererUrl(string $url, int $connexion = 10, int $total = 20, int $essais = 2): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $dernierEchec = '';

    for ($essai = 1; $essai <= max(1, $essais); $essai++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, optionsCurl($connexion, $total));

        $contenu = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erreur  = curl_error($ch);
        curl_close($ch);

        if ($contenu !== false && $code >= 200 && $code < 300) {
            return $contenu;
        }

        $dernierEchec = $erreur ?: "HTTP $code";

        // Une erreur 4xx ne s'arrangera pas au second essai.
        if ($code >= 400 && $code < 500) {
            break;
        }

        if ($essai < $essais) {
            sleep(1);
        }
    }

    error_log("recupererUrl KO ($url) : $dernierEchec");
    return null;
}

/**
 * Vérifie qu'une URL d'image répond bien. Sert à écarter les miniatures
 * introuvables avant de les enregistrer, plutôt que de découvrir le problème
 * sous la forme d'une image cassée dans l'interface.
 */
function urlImageValide(?string $url, int $connexion = 8, int $total = 12): bool
{
    // Une image embarquée (data:) est valide par construction, et n'a rien à
    // aller vérifier sur le réseau.
    if ($url && str_starts_with($url, 'data:')) {
        return true;
    }

    if (!$url || !filter_var($url, FILTER_VALIDATE_URL) || !function_exists('curl_init')) {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, optionsCurl($connexion, $total) + [
        CURLOPT_NOBODY => true,   // requête HEAD : on ne télécharge pas l'image
    ]);

    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}
