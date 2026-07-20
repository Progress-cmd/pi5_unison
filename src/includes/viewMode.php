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
