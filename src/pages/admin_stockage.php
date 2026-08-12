<?php
include_once "../includes/auth.php";
exigerAdmin(false);
include_once "../includes/adminOutils.php";

$pdo = Config::getConnection();
$s = analyserStockage($pdo);

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= assetVersionne('styles/admin.css') ?>">

<article class="containers" data-csrf="<?= $e(jetonCsrf()) ?>">
    <div class="head-bar">
        <a href="?page=admin" data-page="admin" class="redirect">← Administration</a>
    </div>
    <div class="body-bar">
        <div class="admin-chiffres">
            <div class="admin-chiffre">
                <span class="valeur"><?= formaterOctets($s['octets_total']) ?></span>Occupé au total
            </div>
            <div class="admin-chiffre">
                <span class="valeur"><?= count($s['orphelins']) ?></span>Fichiers orphelins
            </div>
            <div class="admin-chiffre">
                <span class="valeur"><?= formaterOctets($s['octets_orphelins']) ?></span>Récupérable
            </div>
            <div class="admin-chiffre">
                <span class="valeur"><?= count($s['manquants']) ?></span>Titres sans fichier
            </div>
        </div>
        <div class="admin-note">
            Dossier analysé : <code><?= $e($s['dossier']) ?></code>
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">
        Fichiers orphelins<span class="result-section-nb"><?= count($s['orphelins']) ?></span>
    </div>
    <div class="body-bar">
        <div class="admin-note">
            Fichiers présents sur le disque qu'aucun titre ne référence : import
            interrompu, ou titre supprimé en base sans son fichier. Les supprimer
            n'a aucun effet sur la bibliothèque.
        </div>

        <?php if ($s['orphelins']): ?>
            <div class="admin-actions" style="margin-bottom: 12px;">
                <button class="admin-btn danger" id="tout-nettoyer">
                    Tout supprimer (<?= formaterOctets($s['octets_orphelins']) ?>)
                </button>
            </div>
            <div class="admin-table-enveloppe">
                <table class="admin-table" id="table-orphelins">
                    <thead><tr><th>Fichier</th><th>Taille</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($s['orphelins'] as $o): ?>
                        <tr data-fichier="<?= $e($o['fichier']) ?>">
                            <td class="principal" data-titre><?= $e($o['fichier']) ?></td>
                            <td><?= formaterOctets($o['octets']) ?></td>
                            <td class="admin-actions">
                                <button class="admin-btn danger" data-action="supprimer-orphelin">Supprimer</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="admin-vide">Aucun fichier orphelin — le disque est propre.</div>
        <?php endif; ?>
    </div>
</article>

<article class="containers">
    <div class="head-bar">
        Titres sans fichier<span class="result-section-nb"><?= count($s['manquants']) ?></span>
    </div>
    <div class="body-bar">
        <div class="admin-note attention">
            Ces titres apparaissent dans la bibliothèque mais ne peuvent pas être
            lus : leur fichier audio a disparu. C'est plus gênant qu'un orphelin,
            car l'anomalie est visible par les utilisateurs.
        </div>

        <?php if ($s['manquants']): ?>
            <div class="admin-table-enveloppe">
                <table class="admin-table">
                    <thead><tr><th>Titre</th><th>Fichier attendu</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($s['manquants'] as $m): ?>
                        <tr data-id="<?= (int) $m['id'] ?>">
                            <td class="principal" data-titre><?= $e($m['title']) ?></td>
                            <td><?= $e($m['file'] ?: '(vide)') ?></td>
                            <td class="admin-actions">
                                <button class="admin-btn danger" data-action="supprimer-titre">Supprimer le titre</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="admin-vide">Tous les titres ont bien leur fichier.</div>
        <?php endif; ?>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Pochettes et photos</div>
    <div class="body-bar">
        <div class="admin-note">
            Recherche les photos d'artistes manquantes et les miniatures de titres
            qui ne répondent plus, puis les reconstitue. Le diagnostic n'écrit rien.
        </div>
        <div class="admin-actions">
            <button class="admin-btn" id="images-diagnostic">Diagnostic</button>
            <button class="admin-btn" id="images-reparer">Réparer</button>
        </div>
        <pre id="images-resultat" class="admin-note" hidden></pre>
    </div>
</article>

<script src="<?= assetVersionne('../scripts/admin.js') ?>"></script>
<script>
(function () {
    const A = window.AdminUnison;
    if (!A) return;

    const zone = document.getElementById('main-content');

    zone.addEventListener('click', async (e) => {
        const btn = e.target.closest('.admin-btn');
        if (!btn) return;

        // --- Orphelins ---
        if (btn.id === 'tout-nettoyer') {
            const n = document.querySelectorAll('#table-orphelins tbody tr').length;
            if (!confirm(`Supprimer les ${n} fichiers orphelins ?\n\nAucun titre de la bibliothèque n'est concerné.`)) return;
            const r = await A.appeler('nettoyer_stockage.php');
            if (r && r.success) navigateTo('admin/stockage');
            return;
        }

        const tr = btn.closest('tr');
        if (!tr) return;

        if (btn.dataset.action === 'supprimer-orphelin') {
            const r = await A.appeler('nettoyer_stockage.php', { fichier: tr.dataset.fichier });
            if (r && r.success) tr.remove();
            return;
        }

        if (btn.dataset.action === 'supprimer-titre') {
            const nom = tr.querySelector('[data-titre]').textContent.trim();
            if (!A.confirmerParNom(nom, 'ce titre')) return;
            const r = await A.appeler('supprimer_titre.php', { track_id: tr.dataset.id });
            if (r && r.success) tr.remove();
        }
    });

    // --- Images ---
    ['images-diagnostic', 'images-reparer'].forEach(id => {
        const btn = document.getElementById(id);
        if (!btn) return;

        btn.addEventListener('click', async () => {
            const reparer = id === 'images-reparer';
            if (reparer && !confirm('Lancer la réparation ?\n\nLes images manquantes seront récupérées auprès de Deezer et de YouTube.')) return;

            const sortie = document.getElementById('images-resultat');
            btn.disabled = true;
            sortie.hidden = false;
            sortie.textContent = 'Analyse en cours… (cela peut prendre une minute)';

            const r = await A.appeler('reparer_images.php', { appliquer: reparer ? '1' : '0' });
            sortie.textContent = r && r.rapport ? r.rapport : 'Aucun résultat.';
            btn.disabled = false;
        });
    });
})();
</script>
