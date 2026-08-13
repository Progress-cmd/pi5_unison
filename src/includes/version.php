<?php
/**
 * Version de l'application — LE seul endroit à modifier.
 *
 * Le numéro était saisi à la main dans la page Compte ; il vit désormais ici
 * et se propage partout. Ce fichier est inclus par includes/auth.php, que
 * toute page et toute action inclut déjà : la constante est donc disponible
 * sans rien ajouter ailleurs.
 *
 * Une version dérivée de git n'est pas possible : seul src/ est monté dans le
 * conteneur, le dépôt n'y est pas accessible (et open_basedir l'interdirait en
 * production). Le commit déployé est en revanche visible dans la console
 * d'administration, publié par le script de mise à jour de l'hôte.
 */

const UNISON_VERSION = '1.0.8';

/** Libellé complet, tel qu'affiché dans l'interface. */
function versionUnison(bool $avecNom = true): string
{
    return ($avecNom ? 'Unison - Version ' : 'v') . UNISON_VERSION;
}
