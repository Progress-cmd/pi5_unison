<?php
/**
 * Forme d'onde d'un fichier audio, pour la barre de progression.
 *
 * Calculée une fois à l'import et rangée dans `tracks.onde` : la barre montre
 * alors la forme du morceau entier, y compris ce qui reste à venir, et
 * naviguer dedans devient utile — on voit où sont les passages forts.
 *
 * Mesuré à 191 ms par titre sur le Raspberry Pi, négligeable à côté du
 * téléchargement qui précède.
 */

/** Nombre d'amplitudes retenues. Assez pour une barre, assez peu pour tenir
 *  dans 255 caractères une fois encodé. */
const ONDE_POINTS = 120;

/**
 * Décode le fichier et en tire ONDE_POINTS amplitudes, encodées en base64.
 *
 * Renvoie null si ffmpeg est absent, si le fichier est illisible ou s'il ne
 * contient pas d'audio exploitable. C'est un état normal : l'appelant ne doit
 * jamais faire échouer un import parce que la décoration n'a pas pu être
 * calculée.
 */
function calculerOnde(string $chemin): ?string
{
    if (!is_readable($chemin)) {
        return null;
    }

    /*
     * Décodage en mono, 4 kHz, 16 bits signés.
     *
     * 4 kHz suffit largement : on ne cherche pas à restituer le son, seulement
     * son enveloppe. À 44,1 kHz le décodage serait dix fois plus long pour un
     * tracé identique à l'œil.
     */
    $commande = sprintf(
        'ffmpeg -v quiet -i %s -ac 1 -filter:a aresample=4000 -map 0:a -c:a pcm_s16le -f data - 2>/dev/null',
        escapeshellarg($chemin)
    );

    $flux = @popen($commande, 'r');
    if ($flux === false) {
        return null;
    }

    $pcm = stream_get_contents($flux);
    pclose($flux);

    if (!is_string($pcm) || strlen($pcm) < 2 * ONDE_POINTS) {
        return null;
    }

    $total = intdiv(strlen($pcm), 2);           // nombre d'échantillons
    $parPoint = intdiv($total, ONDE_POINTS);
    if ($parPoint < 1) {
        return null;
    }

    $amplitudes = '';
    $maxGlobal = 1;
    $bruts = [];

    for ($i = 0; $i < ONDE_POINTS; $i++) {
        /*
         * Un pic par tranche, et non une moyenne : la moyenne d'un signal
         * audio tourne autour de zéro et donnerait une ligne plate. C'est
         * l'amplitude maximale qui dessine l'enveloppe.
         */
        $tranche = unpack('v*', substr($pcm, $i * $parPoint * 2, $parPoint * 2)) ?: [];
        $pic = 0;

        foreach ($tranche as $u) {
            // 'v' lit du non signé : on ramène dans [-32768, 32767].
            $v = $u > 32767 ? $u - 65536 : $u;
            $v = $v < 0 ? -$v : $v;
            if ($v > $pic) $pic = $v;
        }

        $bruts[] = $pic;
        if ($pic > $maxGlobal) $maxGlobal = $pic;
    }

    /*
     * Normalisé sur le pic du morceau : un titre enregistré bas doit dessiner
     * la même amplitude qu'un titre fort, sans quoi la moitié de la
     * discothèque afficherait une ligne écrasée.
     */
    foreach ($bruts as $pic) {
        $amplitudes .= chr((int) round($pic / $maxGlobal * 255));
    }

    return base64_encode($amplitudes);
}

/**
 * Calcule l'onde d'un titre et l'enregistre. Ne lève jamais.
 *
 * @return bool vrai si une onde a été écrite
 */
function enregistrerOnde(PDO $pdo, int $trackId, string $chemin): bool
{
    try {
        $onde = calculerOnde($chemin);
        if ($onde === null) {
            return false;
        }

        $req = $pdo->prepare("UPDATE tracks SET onde = :onde WHERE id = :id");
        $req->execute([':onde' => $onde, ':id' => $trackId]);

        return true;
    } catch (Throwable $e) {
        error_log('Onde non calculée pour le titre ' . $trackId . ' : ' . $e->getMessage());
        return false;
    }
}
