<?php
/**
 * Limitation de tentatives, stockée sur disque dans /tmp.
 *
 * Volontairement sans base de données : le limiteur doit continuer à
 * fonctionner même si MariaDB est indisponible, et il est sollicité avant
 * toute requête SQL. /tmp fait partie de l'open_basedir de production.
 */

const RL_DOSSIER = '/tmp/unison_rl';

/** Clé de compteur pour le client courant (IP), non devinable côté client. */
function rlCle(string $prefixe): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'inconnu';
    return $prefixe . '_' . hash('sha256', $ip);
}

/** Chemin du fichier compteur. */
function rlFichier(string $cle): string
{
    if (!is_dir(RL_DOSSIER)) {
        @mkdir(RL_DOSSIER, 0700, true);
    }
    return RL_DOSSIER . '/' . $cle;
}

/**
 * Nombre de secondes à attendre avant une nouvelle tentative,
 * ou 0 si la tentative est autorisée.
 *
 * @param int $max      tentatives autorisées dans la fenêtre
 * @param int $fenetre  durée de la fenêtre, en secondes
 */
function rlBloque(string $prefixe, int $max = 5, int $fenetre = 900): int
{
    $fichier = rlFichier(rlCle($prefixe));

    if (!is_file($fichier)) {
        return 0;
    }

    $etat = json_decode((string) @file_get_contents($fichier), true);
    if (!is_array($etat) || !isset($etat['n'], $etat['debut'])) {
        return 0;
    }

    // Fenêtre écoulée : le compteur est périmé.
    $reste = ($etat['debut'] + $fenetre) - time();
    if ($reste <= 0) {
        @unlink($fichier);
        return 0;
    }

    return $etat['n'] >= $max ? $reste : 0;
}

/** Enregistre une tentative échouée. */
function rlEchec(string $prefixe, int $fenetre = 900): void
{
    $fichier = rlFichier(rlCle($prefixe));
    $etat = json_decode((string) @file_get_contents($fichier), true);

    // On repart de zéro si aucune tentative en cours ou si la fenêtre a expiré.
    if (!is_array($etat) || !isset($etat['n'], $etat['debut']) || $etat['debut'] + $fenetre < time()) {
        $etat = ['n' => 0, 'debut' => time()];
    }

    $etat['n']++;
    @file_put_contents($fichier, json_encode($etat), LOCK_EX);
}

/** Remet le compteur à zéro (à appeler après un succès). */
function rlReussite(string $prefixe): void
{
    @unlink(rlFichier(rlCle($prefixe)));
}
