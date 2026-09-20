<?php
include_once "../includes/auth.php";
exigerAdmin(false);
include_once "../includes/adminOutils.php";

$pdo = Config::getConnection();

/*
 * Titres et artistes ne sont plus rendus ici.
 *
 * Ces deux tableaux étaient produits en entier, puis filtrés dans le DOM. À
 * quelques centaines de lignes c'était confortable ; ça cesse de l'être quand
 * la discothèque grandit — et le filtre ne pouvait de toute façon trouver que
 * ce qui était déjà chargé. Ils sont désormais paginés et cherchés par la
 * base, via actions/admin/contenu.php, comme la page Journal.
 *
 * Les tableaux ci-dessous restent rendus côté serveur : playlists, genres et
 * tags se comptent en dizaines et ne suivent pas la croissance du catalogue.
 */

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

<article class="containers" id="bloc-titres">
    <div class="head-bar">Titres<span class="result-section-nb" id="nb-titres">…</span></div>
    <div class="body-bar">
        <input type="text" id="filtre-titres" class="admin-filtre"
               placeholder="Rechercher par titre ou artiste…">
        <div class="admin-table-enveloppe">
            <table class="admin-table" id="table-titres">
                <thead>
                    <tr><th>Titre</th><th>Artistes</th><th>Ajouté par</th><th>Fichier</th><th></th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="admin-pagination">
            <button class="admin-btn" data-page-prec="titres" disabled>← Précédents</button>
            <span data-page-libelle="titres">page 1</span>
            <button class="admin-btn" data-page-suiv="titres" disabled>Suivants →</button>
        </div>
    </div>
</article>

<article class="containers" id="bloc-artistes">
    <div class="head-bar">Artistes<span class="result-section-nb" id="nb-artistes">…</span></div>
    <div class="body-bar">
        <input type="text" id="filtre-artistes" class="admin-filtre" placeholder="Rechercher un artiste…">
        <div class="admin-table-enveloppe">
            <table class="admin-table" id="table-artistes">
                <thead><tr><th>Nom</th><th>Titres</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="admin-pagination">
            <button class="admin-btn" data-page-prec="artistes" disabled>← Précédents</button>
            <span data-page-libelle="artistes">page 1</span>
            <button class="admin-btn" data-page-suiv="artistes" disabled>Suivants →</button>
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

    /*
     * Titres et artistes sont paginés par le serveur.
     *
     * La recherche part elle aussi au serveur : filtrer le DOM ne pouvait
     * trouver que les lignes déjà chargées, ce qui devient faux dès la
     * seconde page. Frappe temporisée pour ne pas lancer une requête par
     * caractère.
     */
    function brancherTableau(type, config) {
        const champ   = document.getElementById(config.champ);
        const corps   = document.querySelector('#' + config.table + ' tbody');
        const compteur = document.getElementById(config.compteur);
        const libelle = document.querySelector('[data-page-libelle="' + type + '"]');
        const btnPrec = document.querySelector('[data-page-prec="' + type + '"]');
        const btnSuiv = document.querySelector('[data-page-suiv="' + type + '"]');
        if (!corps) return;

        let page = 1, pages = 1, minuteur = null;

        function cellule(texte, classe) {
            const td = document.createElement('td');
            // textContent : titres et noms d'artistes viennent de YouTube.
            td.textContent = texte;
            if (classe) td.className = classe;
            return td;
        }

        async function charger() {
            const p = new URLSearchParams({ type, page: String(page), recherche: champ.value.trim() });

            let data;
            try {
                const res = await fetch('actions/admin/contenu.php?' + p);
                if (res.status === 404) {
                    window.showToast && window.showToast(
                        'Session non administratrice — reconnectez-vous', 'error', 0);
                    return;
                }
                data = await res.json();
            } catch (err) {
                window.showToast && window.showToast('Erreur réseau', 'error');
                return;
            }
            if (!data || !data.success) return;

            page = data.page;
            pages = data.pages;
            compteur.textContent = data.total;
            libelle.textContent = 'page ' + page + ' / ' + pages;
            btnPrec.disabled = page <= 1;
            btnSuiv.disabled = page >= pages;

            corps.textContent = '';

            if (data.lignes.length === 0) {
                const tr = document.createElement('tr');
                const td = cellule(champ.value.trim() ? 'Aucun résultat.' : 'Aucune ligne.');
                td.colSpan = 5;
                td.className = 'admin-vide';
                tr.appendChild(td);
                corps.appendChild(tr);
                return;
            }

            data.lignes.forEach(l => corps.appendChild(config.ligne(l, cellule)));
        }

        champ.addEventListener('input', () => {
            clearTimeout(minuteur);
            // Tout changement de recherche ramène à la première page : rester
            // page 4 d'un résultat qui n'en compte qu'une n'aurait aucun sens.
            minuteur = setTimeout(() => { page = 1; charger(); }, 250);
        });

        btnPrec.addEventListener('click', () => { if (page > 1) { page--; charger(); } });
        btnSuiv.addEventListener('click', () => { if (page < pages) { page++; charger(); } });

        charger();
    }

    function actions(boutons) {
        const td = document.createElement('td');
        td.className = 'admin-actions';
        boutons.forEach(([action, libelle, danger]) => {
            const b = document.createElement('button');
            b.className = 'admin-btn' + (danger ? ' danger' : '');
            b.dataset.action = action;
            b.textContent = libelle;
            td.appendChild(b);
        });
        return td;
    }

    brancherTableau('titres', {
        champ: 'filtre-titres', table: 'table-titres', compteur: 'nb-titres',
        ligne(t, cellule) {
            const tr = document.createElement('tr');
            tr.dataset.id = t.id;
            tr.append(
                cellule(t.title, 'principal'),
                cellule(t.artistes || '—'),
                cellule(t.ajoute_par || '—'),
                cellule(t.file),
                actions([['renommer', 'Renommer', false], ['supprimer-titre', 'Supprimer', true]])
            );
            tr.querySelector('.principal').dataset.titre = '';
            return tr;
        },
    });

    brancherTableau('artistes', {
        champ: 'filtre-artistes', table: 'table-artistes', compteur: 'nb-artistes',
        ligne(a, cellule) {
            const tr = document.createElement('tr');
            tr.dataset.id = a.id;
            tr.dataset.type = 'artiste';
            tr.append(
                cellule(a.name, 'principal'),
                cellule(String(a.nb_titres)),
                actions([['supprimer-entite', 'Supprimer', true]])
            );
            tr.querySelector('.principal').dataset.titre = '';
            return tr;
        },
    });

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
