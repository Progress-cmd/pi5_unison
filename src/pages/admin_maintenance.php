<?php
include_once "../includes/auth.php";
exigerAdmin(false);
include_once "../includes/adminOutils.php";
include_once "../includes/majConteneurs.php";

$etat = majEtat();
$disponible = majDisponible();

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= assetVersionne('styles/admin.css') ?>">

<article class="containers" data-csrf="<?= $e(jetonCsrf()) ?>">
    <div class="head-bar">
        <a href="?page=admin" data-page="admin" class="redirect">← Administration</a>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Recherche</div>
    <div class="body-bar">
        <div class="admin-note">
            Les index sont reconstruits au démarrage du conteneur. Relancez ici si
            la recherche remonte des titres supprimés, ou ignore des corrections
            récentes.
        </div>
        <div class="admin-actions">
            <button class="admin-btn" id="reindexer">Reconstruire les index</button>
        </div>
        <pre id="reindex-resultat" class="admin-note" hidden></pre>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Conteneurs</div>
    <div class="body-bar">
        <?php if (!$disponible): ?>
            <div class="admin-note attention">
                Mécanisme non installé : le dossier <code><?= $e(MAJ_DOSSIER) ?></code>
                n'existe pas ou n'est pas accessible en écriture. Montez le volume
                dans le fichier compose et vérifiez que <code>www-data</code> peut
                y écrire.
            </div>
        <?php else: ?>
            <div class="admin-note">
                Unison n'a aucun accès à Docker. Ces boutons déposent une demande
                qu'un script de l'hôte ramasse chaque minute — c'est lui, et lui
                seul, qui agit sur les conteneurs.
            </div>

            <div style="margin-bottom: 12px;">
                État :
                <span class="admin-statut <?= $e($etat['statut']) ?>" id="maj-statut">
                    <?= $e($etat['statut']) ?>
                </span>
                <span id="maj-message" style="font-size:12px;color:var(--text-gray);">
                    <?= $e($etat['message']) ?>
                </span>
            </div>

            <div class="admin-note" id="maj-details">
                <?php if ($etat['version']): ?>Version déployée : <code><?= $e($etat['version']) ?></code><br><?php endif; ?>
                <?php if ($etat['depuis']): ?>Dernier passage : <?= $e($etat['depuis']) ?><?php endif; ?>
            </div>

            <div class="admin-actions">
                <?php foreach (majActions() as $cle => $infos): ?>
                    <button class="admin-btn<?= $cle === 'reconstruire' ? ' danger' : '' ?>"
                            data-maj="<?= $e($cle) ?>" title="<?= $e($infos['detail']) ?>">
                        <?= $e($infos['libelle']) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="admin-note" style="margin-top:12px;">
                <?php foreach (majActions() as $infos): ?>
                    <b><?= $e($infos['libelle']) ?></b> — <?= $e($infos['detail']) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</article>

<script src="<?= assetVersionne('../scripts/admin.js') ?>"></script>
<script>
(function () {
    const A = window.AdminUnison;
    if (!A) return;

    // --- Réindexation ---
    const btnIndex = document.getElementById('reindexer');
    if (btnIndex) {
        btnIndex.addEventListener('click', async () => {
            const sortie = document.getElementById('reindex-resultat');
            btnIndex.disabled = true;
            sortie.hidden = false;
            sortie.textContent = 'Reconstruction en cours…';

            const r = await A.appeler('reindexer.php');
            sortie.textContent = r && r.rapport ? r.rapport : 'Échec de la reconstruction.';
            btnIndex.disabled = false;
        });
    }

    // --- Conteneurs ---
    let sondage = null;

    function afficherEtat(e) {
        const badge = document.getElementById('maj-statut');
        if (!badge) return;
        badge.className = 'admin-statut ' + e.statut;
        badge.textContent = e.statut;
        document.getElementById('maj-message').textContent = e.message || '';

        const details = document.getElementById('maj-details');
        details.innerHTML = (e.version ? 'Version déployée : <code>' + e.version + '</code><br>' : '')
                          + (e.depuis ? 'Dernier passage : ' + e.depuis : '');
    }

    async function sonder() {
        try {
            const res = await fetch('actions/admin/etat_maj.php');
            if (!res.ok) return;
            const data = await res.json();
            afficherEtat(data.etat);

            // On arrête de sonder dès que l'hôte a fini.
            if (!data.demande_en_cours && data.etat.statut !== 'en_cours') {
                clearInterval(sondage);
                sondage = null;
            }
        } catch (e) { /* le sondage reprendra au prochain tick */ }
    }

    document.querySelectorAll('[data-maj]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const action = btn.dataset.maj;
            const message = action === 'reconstruire'
                ? "Reconstruire les conteneurs ?\n\nLe service sera INTERROMPU plusieurs minutes."
                : "Recharger l'application ?\n\nSans coupure de service.";
            if (!confirm(message)) return;

            const r = await A.appeler('demander_maj.php', { action });
            if (r && r.success && !sondage) {
                sondage = setInterval(sonder, 5000);
                sonder();
            }
        });
    });

    // Si une mise à jour tournait déjà, on reprend le suivi en arrivant.
    if (document.getElementById('maj-statut')?.textContent.trim() === 'en_cours') {
        sondage = setInterval(sonder, 5000);
    }
})();
</script>
