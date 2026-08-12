<?php
include_once "../includes/auth.php";
exigerAdmin(false);
include_once "../includes/adminOutils.php";

$pdo = Config::getConnection();

// Le catalogue est petit (quelques centaines de lignes au plus) : on rend tout
// et on filtre côté client, ce qui évite un aller-retour par frappe.
$titres = $pdo->query("
    SELECT tracks.id, tracks.title, tracks.duration, tracks.file,
           GROUP_CONCAT(DISTINCT artists.name ORDER BY artists.name SEPARATOR ', ') AS artistes,
           users.username AS ajoute_par
    FROM tracks
    LEFT JOIN artist__track ON artist__track.track_id = tracks.id
    LEFT JOIN artists       ON artists.id = artist__track.artist_id
    LEFT JOIN users         ON users.id = tracks.`added-by_id`
    GROUP BY tracks.id, tracks.title, tracks.duration, tracks.file, users.username
    ORDER BY tracks.title
")->fetchAll(PDO::FETCH_ASSOC);

$artistes = $pdo->query("
    SELECT artists.id, artists.name, COUNT(artist__track.track_id) AS nb_titres
    FROM artists
    LEFT JOIN artist__track ON artist__track.artist_id = artists.id
    GROUP BY artists.id, artists.name
    ORDER BY nb_titres DESC, artists.name
")->fetchAll(PDO::FETCH_ASSOC);

$playlists = $pdo->query("
    SELECT playlists.id, playlists.name, users.username AS auteur,
           COUNT(track__playlist.track_id) AS nb_titres
    FROM playlists
    LEFT JOIN users           ON users.id = playlists.`created-by_id`
    LEFT JOIN track__playlist ON track__playlist.playlist_id = playlists.id
    WHERE playlists.name NOT IN ('Wait Tracks', 'Favorite Tracks')
    GROUP BY playlists.id, playlists.name, users.username
    ORDER BY playlists.name
")->fetchAll(PDO::FETCH_ASSOC);

$genres = $pdo->query("
    SELECT genres.id, genres.name, COUNT(track__genre.track_id) AS nb_titres
    FROM genres LEFT JOIN track__genre ON track__genre.genre_id = genres.id
    GROUP BY genres.id, genres.name ORDER BY genres.name
")->fetchAll(PDO::FETCH_ASSOC);

$tags = $pdo->query("
    SELECT tags.id, tags.name, COUNT(tag__track.track_id) AS nb_titres
    FROM tags LEFT JOIN tag__track ON tag__track.tag_id = tags.id
    GROUP BY tags.id, tags.name ORDER BY tags.name
")->fetchAll(PDO::FETCH_ASSOC);

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= assetVersionne('styles/admin.css') ?>">

<article class="containers" data-csrf="<?= $e(jetonCsrf()) ?>">
    <div class="head-bar">
        <a href="?page=admin" data-page="admin" class="redirect">← Administration</a>
    </div>
    <div class="body-bar">
        <div class="admin-note attention">
            Les suppressions sont définitives et sans corbeille. Supprimer un titre
            efface aussi son fichier audio et le retire de toutes les playlists.
            Supprimer un artiste ne supprime pas ses morceaux, seulement le
            rattachement.
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Titres<span class="result-section-nb"><?= count($titres) ?></span></div>
    <div class="body-bar">
        <input type="text" id="filtre-titres" class="admin-filtre"
               placeholder="Filtrer par titre, artiste ou fichier…">
        <div class="admin-table-enveloppe">
            <table class="admin-table" id="table-titres">
                <thead>
                    <tr><th>Titre</th><th>Artistes</th><th>Ajouté par</th><th>Fichier</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($titres as $t): ?>
                    <tr data-id="<?= (int) $t['id'] ?>">
                        <td class="principal" data-titre><?= $e($t['title']) ?></td>
                        <td><?= $e($t['artistes'] ?: '—') ?></td>
                        <td><?= $e($t['ajoute_par'] ?: '—') ?></td>
                        <td><?= $e($t['file']) ?></td>
                        <td class="admin-actions">
                            <button class="admin-btn" data-action="renommer">Renommer</button>
                            <button class="admin-btn danger" data-action="supprimer-titre">Supprimer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$titres): ?><div class="admin-vide">Aucun titre.</div><?php endif; ?>
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Artistes<span class="result-section-nb"><?= count($artistes) ?></span></div>
    <div class="body-bar">
        <input type="text" id="filtre-artistes" class="admin-filtre" placeholder="Filtrer…">
        <div class="admin-table-enveloppe">
            <table class="admin-table" id="table-artistes">
                <thead><tr><th>Nom</th><th>Titres</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($artistes as $a): ?>
                    <tr data-id="<?= (int) $a['id'] ?>" data-type="artiste">
                        <td class="principal" data-titre><?= $e($a['name']) ?></td>
                        <td><?= (int) $a['nb_titres'] ?></td>
                        <td class="admin-actions">
                            <button class="admin-btn danger" data-action="supprimer-entite">Supprimer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Playlists<span class="result-section-nb"><?= count($playlists) ?></span></div>
    <div class="body-bar">
        <div class="admin-table-enveloppe">
            <table class="admin-table" id="table-playlists">
                <thead><tr><th>Nom</th><th>Auteur</th><th>Titres</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($playlists as $p): ?>
                    <tr data-id="<?= (int) $p['id'] ?>" data-type="playlist">
                        <td class="principal" data-titre><?= $e($p['name']) ?></td>
                        <td><?= $e($p['auteur'] ?: '—') ?></td>
                        <td><?= (int) $p['nb_titres'] ?></td>
                        <td class="admin-actions">
                            <button class="admin-btn danger" data-action="supprimer-entite">Supprimer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$playlists): ?><div class="admin-vide">Aucune playlist créée.</div><?php endif; ?>
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Genres et étiquettes</div>
    <div class="body-bar">
        <div class="admin-table-enveloppe">
            <table class="admin-table">
                <thead><tr><th>Nom</th><th>Type</th><th>Titres</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($genres as $g): ?>
                    <tr data-id="<?= (int) $g['id'] ?>" data-type="genre">
                        <td class="principal" data-titre><?= $e($g['name']) ?></td>
                        <td>Genre</td>
                        <td><?= (int) $g['nb_titres'] ?></td>
                        <td class="admin-actions">
                            <button class="admin-btn danger" data-action="supprimer-entite">Supprimer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($tags as $t): ?>
                    <tr data-id="<?= (int) $t['id'] ?>" data-type="tag">
                        <td class="principal" data-titre><?= $e($t['name']) ?></td>
                        <td>Étiquette</td>
                        <td><?= (int) $t['nb_titres'] ?></td>
                        <td class="admin-actions">
                            <button class="admin-btn danger" data-action="supprimer-entite">Supprimer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</article>

<script src="<?= assetVersionne('../scripts/admin.js') ?>"></script>
<script>
(function () {
    const A = window.AdminUnison;
    if (!A) return;

    A.brancherFiltre('filtre-titres', 'table-titres');
    A.brancherFiltre('filtre-artistes', 'table-artistes');

    // Un seul écouteur pour toute la page : les lignes portent leur identité.
    document.getElementById('main-content').addEventListener('click', async (e) => {
        const btn = e.target.closest('.admin-btn[data-action]');
        if (!btn) return;

        const tr  = btn.closest('tr');
        const id  = tr.dataset.id;
        const nom = tr.querySelector('[data-titre]').textContent.trim();

        if (btn.dataset.action === 'renommer') {
            const nouveau = prompt('Nouveau titre :', nom);
            if (nouveau === null || nouveau.trim() === '' || nouveau.trim() === nom) return;

            const r = await A.appeler('renommer_titre.php', { track_id: id, titre: nouveau.trim() });
            if (r && r.success) tr.querySelector('[data-titre]').textContent = r.titre;
            return;
        }

        if (btn.dataset.action === 'supprimer-titre') {
            if (!A.confirmerParNom(nom, 'ce titre (base + fichier audio)')) return;
            const r = await A.appeler('supprimer_titre.php', { track_id: id });
            if (r && r.success) tr.remove();
            return;
        }

        if (btn.dataset.action === 'supprimer-entite') {
            if (!A.confirmerParNom(nom, 'cet élément')) return;
            const r = await A.appeler('supprimer_entite.php', { type: tr.dataset.type, id });
            if (r && r.success) tr.remove();
        }
    });
})();
</script>
