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
 * suivie d'un filtre de propriétaire facultatif ; elle est ici en un seul
 * endroit.
 *
 * Seule la file d'attente est masquée. Les favoris, eux, sont une playlist
 * comme une autre : ceux du second compte du foyer doivent rester visibles en
 * mode commun. Je les avais un temps réservés à leur propriétaire — c'était
 * une mauvaise lecture de la demande, le vrai défaut étant l'affichage
 * « 0 titre – 0:0 min » d'une playlist vide, corrigé par resumePlaylist().
 *
 * @return array{0:string,1:array} la condition et ses paramètres
 */
function clausePlaylistsVisibles(): array
{
    $conditions = ["playlists.name != 'Wait Tracks'"];
    $parametres = [];

    if (isPersonalView()) {
        $conditions[] = "playlists.`created-by_id` = :uid";
        $parametres[':uid'] = (int) ($_SESSION['user']['id'] ?? 0);
    }

    return [implode(' AND ', $conditions), $parametres];
}
