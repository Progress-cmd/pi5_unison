<?php
/**
 * Diagnostic des accès réseau sortants du conteneur.
 *
 *   docker compose -f docker/docker-compose-prod.yml --env-file docker/.env \
 *       exec app php /var/www/html/src/includes/diagnosticReseau.php
 *
 * Sert à distinguer les causes d'un « Resolving timed out » : résolveur lent,
 * IPv6 sans route, ou service réellement injoignable. Les trois se corrigent
 * différemment, et le message d'erreur de cURL ne permet pas de trancher.
 *
 * Lecture seule : ce script n'écrit rien et ne modifie aucune configuration.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script s'exécute en ligne de commande uniquement.\n");
}

require_once __DIR__ . '/artistImage.php';

/** Chronomètre une opération et renvoie [résultat, durée en secondes]. */
function chrono(callable $operation): array
{
    $debut = microtime(true);
    $resultat = $operation();
    return [$resultat, microtime(true) - $debut];
}

echo "=== Résolveurs configurés ===\n";
$resolv = @file_get_contents('/etc/resolv.conf');
foreach (preg_split('/\r?\n/', (string) $resolv) as $ligne) {
    if (preg_match('/^(nameserver|search|options)/', $ligne)) {
        echo "  $ligne\n";
    }
}

echo "\n=== Résolution DNS ===\n";
foreach (['api.deezer.com', 'i.ytimg.com', 'www.youtube.com'] as $hote) {
    [$ipv4, $t4] = chrono(fn() => @gethostbynamel($hote));
    printf("  %-18s IPv4 : %-7s %5.2f s  %s\n", $hote,
        $ipv4 ? 'ok' : 'ÉCHEC', $t4, $ipv4 ? $ipv4[0] : '');

    // Une résolution IPv6 lente sans route utilisable est LA cause la plus
    // fréquente : cURL demande l'AAAA en premier et attend son expiration.
    [$ipv6, $t6] = chrono(fn() => @dns_get_record($hote, DNS_AAAA));
    printf("  %-18s IPv6 : %-7s %5.2f s  %s\n", '',
        $ipv6 ? 'présent' : 'absent', $t6,
        $t6 > 1.5 ? '← lent, forcer IPv4 est justifié' : '');
}

echo "\n=== Requêtes HTTPS ===\n";
$cibles = [
    'Deezer (photos artistes)' => 'https://api.deezer.com/search/artist?limit=1&q=test',
    'YouTube (miniatures)'     => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
];

foreach ($cibles as $nom => $url) {
    foreach ([['sans forçage', null], ['forcé IPv4', CURL_IPRESOLVE_V4]] as [$libelle, $mode]) {
        [$code, $duree] = chrono(function () use ($url, $mode) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_USERAGENT      => 'Unison/1.0',
            ]);
            if ($mode !== null) {
                curl_setopt($ch, CURLOPT_IPRESOLVE, $mode);
            }
            curl_exec($ch);
            $erreur = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return $erreur ?: (string) $code;
        });

        printf("  %-26s %-12s %-24s %5.2f s\n", $nom, $libelle, $code, $duree);
    }
}

echo "\n=== Fonctions réellement utilisées par l'application ===\n";
[$img, $t] = chrono(fn() => fetchArtistImage('Placebo'));
printf("  fetchArtistImage(Placebo)  %-8s %5.2f s\n", $img ? 'ok' : 'ÉCHEC', $t);

[$ok, $t] = chrono(fn() => urlImageValide('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'));
printf("  urlImageValide(miniature)  %-8s %5.2f s\n", $ok ? 'ok' : 'ÉCHEC', $t);

echo "\n--- Lecture ---\n";
echo "  IPv4 lent (> 2 s)      : résolveur du réseau lent ou saturé.\n";
echo "  IPv6 lent, IPv4 rapide : c'était la cause ; le forçage IPv4 la corrige.\n";
echo "  Tout rapide ici        : le problème venait des délais trop courts.\n";
echo "  Tout en échec          : le conteneur n'a pas de route sortante.\n";
