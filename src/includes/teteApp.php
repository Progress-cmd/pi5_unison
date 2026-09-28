<?php
/**
 * Balises d'identité de l'application, communes à toutes les pages servies
 * directement : manifeste, icônes, couleur de barre système.
 *
 * Elles ne vivaient que dans index.php. Or on n'y arrive pas toujours en
 * premier : sans session, index.php redirige vers login.php, et c'est cette
 * page-là que le navigateur avait sous les yeux au moment de l'installation.
 * Sans manifeste ni icône, il n'avait rien à installer — d'où le raccourci
 * sans dessin sur le téléphone.
 */
function balisesApplication(): void
{
    $v = static fn (string $f): string => assetVersionne($f);
    ?>
    <link rel="manifest" href="<?= $v('manifest.webmanifest') ?>">
    <?php
    /*
     * Le SVG d'abord : c'est la seule icône qui suive vraiment le thème du
     * navigateur, parce qu'elle est réévaluée à chaque affichage. Les PNG
     * restent en repli pour les navigateurs qui ignorent les favicons
     * vectorielles — deux tailles, parce qu'à 16 px la réduction de la 32
     * brouille le motif.
     */
    ?>
    <link rel="icon" type="image/svg+xml" href="<?= $v('icones/favicon.svg') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $v('icones/favicon-16.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $v('icones/favicon-32.png') ?>">
    <link rel="apple-touch-icon" href="<?= $v('icones/apple-touch-icon.png') ?>">

    <?php
    /*
     * La couleur de la barre système suit le thème de l'appareil. Le manifeste
     * n'en accepte qu'une seule, figée : ces deux balises la corrigent, sans
     * quoi une barre crème surmontait l'application en mode sombre.
     */
    ?>
    <meta name="theme-color" content="#f8f7f5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#17150f" media="(prefers-color-scheme: dark)">

    <?php /* iOS ignore le manifeste : il lui faut ses propres balises. */ ?>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Unison">
    <meta name="mobile-web-app-capable" content="yes">
    <?php
}
