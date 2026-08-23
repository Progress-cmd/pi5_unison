<?php
/**
 * Gestion du mode d'affichage utilisateur :
 *  - 'mixed'    : contenu de tous les utilisateurs (playlists, notes...)
 *  - 'personal' : uniquement le contenu créé par l'utilisateur courant
 *
 * La préférence est persistée dans users.view_mode et mise en cache
 * dans $_SESSION['user']['view_mode'].
 */

function currentViewMode(): string
{
    $mode = $_SESSION['user']['view_mode'] ?? 'mixed';
    return $mode === 'personal' ? 'personal' : 'mixed';
}

function isPersonalView(): bool
{
    return currentViewMode() === 'personal';
}

/**
 * Condition SQL sélectionnant les playlists à présenter dans un listing.
 *
 * Elle était recopiée sur trois pages sous la forme « name != 'Wait Tracks' »
 * suivie d'un filtre de propriétaire facultatif. Deux règles s'y ajoutent :
 *
 *  - la file d'attente reste masquée, comme avant ;
 *  - les favoris sont personnels. Ils étaient listés pour TOUS les comptes en
 *    mode commun : l'accueil affichait donc deux cartes « Favorite Tracks »
 *    identiques, dont celle — souvent vide — du second compte du foyer.
 *
 * @param bool $avecFavoris false pour les masquer complètement, sur les pages
 *                          qui leur consacrent déjà une section.
 * @return array{0:string,1:array} la clause et ses paramètres
 */
function clausePlaylistsVisibles(bool $avecFavoris = true): array
{
    $moi = (int) ($_SESSION['user']['id'] ?? 0);

    $conditions = ["playlists.name != 'Wait Tracks'"];
    $parametres = [];

    if ($avecFavoris) {
        // Deux noms de paramètre distincts : une requête préparée native
        // n'accepte pas deux fois le même marqueur.
        $conditions[] = "(playlists.name != 'Favorite Tracks'"
                      . " OR playlists.`created-by_id` = :moi)";
        $parametres[':moi'] = $moi;
    } else {
        $conditions[] = "playlists.name != 'Favorite Tracks'";
    }

    if (isPersonalView()) {
        $conditions[] = "playlists.`created-by_id` = :uid";
        $parametres[':uid'] = $moi;
    }

    return [implode(' AND ', $conditions), $parametres];
}
