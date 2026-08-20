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
    <div class="head-bar">Format audio</div>
    <div class="body-bar">
        <div class="admin-note">
            Les imports produisent désormais du m4a, repris tel quel depuis la
            source. Les fichiers plus anciens sont en WAV : même contenu, une
            dizaine de fois plus lourd. La conversion les aligne, un titre à la
            fois — vous pouvez l'interrompre et la reprendre, chaque titre
            converti est acquis.
        </div>

        <div id="conv-etat" class="admin-note">Analyse…</div>

        <div class="admin-actions">
            <button class="admin-btn" id="conv-lancer" disabled>Convertir</button>
            <button class="admin-btn" id="conv-arreter" hidden>Arrêter</button>
        </div>

        <div id="conv-barre" hidden>
            <div class="conv-jauge"><div class="conv-jauge-remplie"></div></div>
            <div id="conv-detail" class="admin-note"></div>
        </div>

        <pre id="conv-echecs" class="admin-note" hidden></pre>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Conteneurs</div>
    <div class="body-bar">
        <?php if (!$disponible): $etat = majEtatInstallation(); ?>
            <div class="admin-note attention">
                <b>Mécanisme non installé.</b><br>
                <?= $e($etat['message']) ?>
            </div>
            <pre class="admin-note"><?= $e($etat['correctif']) ?></pre>
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

            <div class="admin-note">
                Version de l'application : <code><?= versionUnison(false) ?></code>
                <span style="opacity:.7">(définie dans <code>src/includes/version.php</code>)</span>
            </div>

            <div class="admin-note" id="maj-details">
                <?php if ($etat['version']): ?>Commit déployé : <code><?= $e($etat['version']) ?></code><br><?php endif; ?>
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

    // --- Format audio ---
    (function () {
        const etat     = document.getElementById('conv-etat');
        const lancer   = document.getElementById('conv-lancer');
        const arreter  = document.getElementById('conv-arreter');
        const barre    = document.getElementById('conv-barre');
        const remplie  = document.querySelector('.conv-jauge-remplie');
        const detail   = document.getElementById('conv-detail');
        const echecs   = document.getElementById('conv-echecs');
        if (!etat) return;

        let aConvertir = [];
        let interrompu = false;

        function octets(n) {
            const u = ['o', 'Ko', 'Mo', 'Go'];
            let i = 0;
            while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
            return n.toFixed(i === 0 ? 0 : 1) + ' ' + u[i];
        }

        async function analyser() {
            const r = await A.appeler('lister_conversions.php');
            if (!r || !r.success) { etat.textContent = 'Analyse impossible.'; return; }

            aConvertir = r.titres || [];

            if (aConvertir.length === 0) {
                etat.textContent = r.manquants
                    ? `Tous les fichiers présents sont au bon format (${r.manquants} titre(s) sans fichier sur le disque — voir Stockage).`
                    : 'Tous les fichiers sont déjà au bon format.';
                lancer.disabled = true;
                return;
            }

            etat.textContent = `${aConvertir.length} titre(s) à convertir, ${r.octets_lisible} sur le disque.`;
            lancer.disabled = false;
        }

        async function convertir() {
            interrompu = false;
            lancer.hidden = true;
            arreter.hidden = false;
            barre.hidden = false;
            echecs.hidden = true;
            echecs.textContent = '';

            let faits = 0, avant = 0, apres = 0;
            const rates = [];

            for (const t of aConvertir) {
                if (interrompu) break;

                detail.textContent = `${faits + 1} / ${aConvertir.length} — ${t.titre}`;

                const r = await A.appeler('convertir_titre.php', { track_id: t.id });

                if (r && r.success) {
                    avant += r.avant || 0;
                    apres += r.apres || 0;
                } else {
                    rates.push(`${t.titre} — ${(r && r.message) || 'échec'}`);
                }

                faits++;
                remplie.style.width = (faits / aConvertir.length * 100) + '%';
            }

            arreter.hidden = true;
            lancer.hidden = false;

            const gain = avant > 0 ? (avant / Math.max(apres, 1)).toFixed(1) : '0';
            detail.textContent = interrompu
                ? `Interrompu après ${faits} titre(s). ${octets(avant)} → ${octets(apres)} (×${gain}).`
                : `Terminé : ${faits - rates.length} converti(s), ${octets(avant)} → ${octets(apres)} (×${gain}).`;

            if (rates.length) {
                echecs.hidden = false;
                // textContent : les titres viennent de la base, donc de l'extérieur.
                echecs.textContent = 'Échecs (fichiers d\'origine conservés) :\n' + rates.join('\n');
            }

            await analyser();
        }

        lancer.addEventListener('click', () => {
            if (!confirm(`Convertir ${aConvertir.length} fichier(s) ?\n\n`
                       + `Les originaux sont supprimés après vérification de chaque conversion.`)) return;
            convertir();
        });

        arreter.addEventListener('click', () => {
            interrompu = true;
            arreter.disabled = true;
            detail.textContent += ' — arrêt après le titre en cours…';
            setTimeout(() => { arreter.disabled = false; }, 100);
        });

        analyser();
    })();

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
