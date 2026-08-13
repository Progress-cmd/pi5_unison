<?php
include_once "../includes/auth.php";
exigerAdmin(false);
include_once "../includes/sqlTerminal.php";

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

// Méta-commandes et noms de tables alimentent la complétion par Tab.
$complements = array_keys(sqlMetaCommandes());

try {
    $tables = consolePdo()->query(
        "SELECT table_name FROM information_schema.TABLES
          WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
          ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $complements = array_merge($complements, $tables);
} catch (PDOException $ex) {
    // La complétion est un confort : son absence ne doit pas empêcher
    // d'ouvrir le terminal, qui sert justement à diagnostiquer une base malade.
    error_log('admin_sql : liste des tables indisponible — ' . $ex->getMessage());
}

$ecriture = sqlEcritureActive();
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
        Terminal SQL
        <span class="more-bar"><?= $ecriture ? 'mode écriture actif' : 'lecture seule' ?></span>
    </div>
    <div class="body-bar">
        <div class="admin-note">
            Un client SQL complet sur les bases du projet. Tout ce qui n'est pas
            préfixé d'une contre-oblique part directement à MariaDB.
            <b>Le terminal démarre en lecture seule</b> : <code>\ecriture on</code>
            autorise les modifications pour quinze minutes.
            <code>\aide</code> donne les méta-commandes.
        </div>

        <div class="admin-note attention">
            Ce terminal ne connaît rien au projet : il ne réindexe pas MeiliSearch,
            ne supprime pas les fichiers audio d'un titre effacé et ne vérifie
            aucune cohérence. Pour tout ce que les pages
            <a href="?page=admin/contenu" data-page="admin/contenu">Contenu</a> et
            <a href="?page=admin/stockage" data-page="admin/stockage">Stockage</a>
            savent faire, elles restent le bon outil. Avant d'écrire ici, une
            sauvegarde se prend en une commande sur le serveur :
            <code>backup_unison</code>
        </div>

        <!--
            Terminal piloté par scripts/console.js, partagé avec la page
            Console : seul le point d'entrée change (data-endpoint).
        -->
        <div class="console<?= $ecriture ? ' console-mode-ecriture' : '' ?>" id="console"
             data-endpoint="actions/admin/sql.php"
             data-commandes="<?= $e(implode(',', $complements)) ?>">

            <div class="console-sortie" id="console-sortie" tabindex="0" role="log" aria-live="polite">
                <div class="console-bloc console-titre">Terminal SQL — Unison <?= $e(versionUnison(false)) ?></div>
                <div class="console-bloc console-texte">MariaDB · base « <?= $e(consoleBaseCourante()) ?> » · lecture seule</div>
                <div class="console-bloc console-texte">« \aide » pour les méta-commandes, « \tables » pour la liste des tables.</div>
            </div>

            <div class="console-saisie">
                <label for="console-commande" class="console-invite" id="console-invite"><?= $e(sqlInvite()) ?></label>
                <textarea id="console-commande" class="console-champ" rows="1"
                          autocomplete="off" autocapitalize="off" autocorrect="off"
                          spellcheck="false" placeholder="SELECT * FROM tracks LIMIT 10"></textarea>
            </div>
        </div>

        <div class="admin-actions">
            <button class="admin-btn" id="console-effacer">Effacer l'écran</button>
            <span class="console-aide-touches">
                Entrée exécute · Maj+Entrée passe à la ligne · ↑ ↓ historique · Tab complète
            </span>
        </div>
    </div>
</article>

<script src="<?= assetVersionne('../scripts/admin.js') ?>"></script>
<script src="<?= assetVersionne('../scripts/console.js') ?>"></script>
