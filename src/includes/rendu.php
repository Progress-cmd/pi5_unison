<?php
/**
 * Rendu partagé des fragments de contenu.
 *
 * La ligne de titre (« mini-song ») était recopiée dans onze endroits :
 * l'accueil, la bibliothèque, le compte, la file d'attente, les playlists,
 * la page artiste, et trois fois côté JavaScript. Les copies avaient divergé —
 * certaines échappaient le titre, d'autres non, certaines posaient un
 * onclick, d'autres un écouteur — et une correction n'en touchait jamais
 * qu'une seule.
 *
 * Pendant du script src/scripts/ligneTitre.js, qui produit exactement la même
 * structure côté client. Toute évolution du gabarit doit passer par les deux.
 */

/**
 * Une ligne de titre.
 *
 * @param array $titre  attend au moins id, title ; puis img, artists_names.
 * @param array $options
 *   - sous_titre (?string) remplace le nom des artistes (« … - 3 écoutes ») ;
 *   - classes    (string)  classes CSS supplémentaires ;
 *   - badge      (bool)    affiche la pastille « EN COURS » ;
 *   - index      (?int)    position dans la file : posée en data-index, elle
 *                          fait de la ligne un point d'entrée dans la queue ;
 *   - menu       (bool)    bouton « … » du menu contextuel (vrai par défaut).
 */
function ligneTitre(array $titre, array $options = []): string
{
    $id         = (int) ($titre['id'] ?? 0);
    $classes    = trim('content mini-song ' . ($options['classes'] ?? ''));
    $sousTitre  = $options['sous_titre'] ?? ($titre['artists_names'] ?? '');
    $avecMenu   = $options['menu'] ?? true;
    $index      = $options['index'] ?? null;

    $e = static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

    // data-piste : c'est cet attribut, et lui seul, que la délégation de clic
    // du routeur écoute. Les résultats de recherche, qui gèrent leurs propres
    // clics, ne le portent pas.
    $html  = '<div class="' . $e($classes) . '" data-piste data-track-id="' . $id . '"';
    $html .= $index !== null ? ' data-index="' . (int) $index . '"' : '';
    $html .= '>';

    $html .= '<img src="' . $e($titre['img'] ?? '') . '" class="song-img" alt="">';
    $html .= '<div class="song-infos">';
    $html .= '<div class="song-title">' . $e($titre['title'] ?? '') . '</div>';
    $html .= '<div class="song-artist">' . $e($sousTitre) . '</div>';
    $html .= '</div>';

    if (!empty($options['badge'])) {
        $html .= '<div class="running badge">EN COURS</div>';
    }

    if ($avecMenu) {
        $html .= '<button class="buttons material-symbols-outlined" aria-label="Options du titre">more_vert</button>';
    }

    return $html . '</div>';
}

/**
 * Ligne d'attente vide, ou message d'absence.
 *
 * Sert partout où une liste peut être vide : sans ça chaque page réinventait
 * son propre « Aucune écoute pour le moment », parfois en oubliant le cas.
 */
function ligneVide(string $message): string
{
    return '<div class="content ligne-vide"><em>'
         . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
         . '</em></div>';
}
