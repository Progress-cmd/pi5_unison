<?php
include_once "../includes/auth.php";
exigerAdmin(false);
include_once "../includes/console.php";

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

// Les noms de commandes servent à la complétion par Tab, côté navigateur.
$noms = array_keys(consoleCommandes());
?>
<link rel="stylesheet" href="<?= assetVersionne('styles/admin.css') ?>">
<link rel="stylesheet" href="<?= assetVersionne('styles/console.css') ?>">

<article class="containers" data-csrf="<?= $e(jetonCsrf()) ?>">
    <div class="head-bar">
        <a href="?page=admin" data-page="admin" class="redirect">← Administration</a>
    </div>
</article>

<article class="containers">
    <div class="head-bar">
        Console
        <span class="more-bar">lecture seule</span>
    </div>
    <div class="body-bar">
        <div class="admin-note">
            Un terminal pour interroger le projet sans ouvrir de shell dans le
            conteneur. <b>Aucune commande ne modifie quoi que ce soit</b> : les
            opérations destructrices gardent leurs pages dédiées et leurs
            confirmations. Tapez <code>aide</code> pour la liste des commandes,
            <code>sante</code> pour un état des lieux.
        </div>

        <!--
            Le terminal est piloté par scripts/console.js, qui parle à
            actions/admin/console.php. Les commandes connues sont déposées ici
            en attribut de données pour alimenter la complétion par Tab, sans
            second aller-retour au serveur.
        -->
        <div class="console" id="console"
             data-endpoint="actions/admin/console.php"
             data-commandes="<?= $e(implode(',', $noms)) ?>">

            <div class="console-sortie" id="console-sortie" tabindex="0" role="log" aria-live="polite">
                <div class="console-bloc console-titre">Console d'administration Unison <?= $e(versionUnison(false)) ?></div>
                <div class="console-bloc console-texte">Tapez « aide » pour la liste des commandes.</div>
            </div>

            <div class="console-saisie">
                <label for="console-commande" class="console-invite" id="console-invite">unison:<?= $e(consoleBaseCourante()) ?> $</label>
                <textarea id="console-commande" class="console-champ" rows="1"
                          autocomplete="off" autocapitalize="off" autocorrect="off"
                          spellcheck="false" placeholder="aide"></textarea>
            </div>
        </div>

        <div class="admin-actions">
            <button class="admin-btn" id="console-effacer">Effacer l'écran</button>
            <span class="console-aide-touches">
                ↑ ↓ historique · Tab complétion · Échap efface la ligne
            </span>
        </div>
    </div>
</article>

<script src="<?= assetVersionne('../scripts/admin.js') ?>"></script>
<script src="<?= assetVersionne('../scripts/console.js') ?>"></script>
