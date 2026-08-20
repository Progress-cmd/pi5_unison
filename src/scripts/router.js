const mainContent = document.getElementById('main-content'); // Pointe vers le contenu de la page
let previousPage = null; // Garde en mémoire la page précédente

// Cartes des pages
const routes = {
    'home':     'pages/home.php',
    'home/queue': '',
    'library':  'pages/library.php',
    'library/titres': 'pages/titres.php',
    'library/artists': 'pages/artists.php',
    'library/playlists': 'pages/playlists.php',
    'library/playlists/add_playlist': 'pages/add_playlist.php',
    'library/playlist': 'pages/playlist.php',
    'search':   'pages/search.php',
    'player/queue': 'pages/queue.php',
    'import':      'pages/import.php',
    'account':  'pages/account.php',
    'account/infos': 'pages/infos.php',
    'library/edit-playlist': 'pages/edit_playlist.php',
    'library/titre': 'pages/titre.php',
    'library/artiste': 'pages/artiste.php',

    /*
     * Section d'administration. Ce fichier est servi à tout le monde : ces
     * routes ne sont donc pas un secret, et n'ont pas à l'être. La seule garde
     * qui compte est exigerAdmin() en tête de chaque page — une session
     * ordinaire qui tape ?page=admin reçoit un 404.
     */
    'admin':             'pages/admin.php',
    'admin/contenu':     'pages/admin_contenu.php',
    'admin/stockage':    'pages/admin_stockage.php',
    'admin/comptes':     'pages/admin_comptes.php',
    'admin/maintenance': 'pages/admin_maintenance.php',
    'admin/journal':     'pages/admin_journal.php',
    'admin/console':     'pages/admin_console.php',
    'admin/sql':         'pages/admin_sql.php',
};

/*
 * Filet de sécurité sur les images : pochettes et photos d'artistes viennent
 * de services externes (YouTube, Deezer) et peuvent manquer, expirer ou ne pas
 * répondre. Sans ça le navigateur affiche une icône cassée.
 *
 * Un seul écouteur en phase de capture — l'événement `error` d'une image ne
 * remonte pas — donc valable aussi pour les pages injectées par le routeur.
 */
window.POCHETTE_DEFAUT =
    "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E"
    + "%3Crect width='64' height='64' fill='%23dfcbc0'/%3E"
    + "%3Cpath d='M40 18v18a6 6 0 1 1-4-5.7V24l-12 3v15a6 6 0 1 1-4-5.7V24z' fill='%237A736C'/%3E%3C/svg%3E";

document.addEventListener('error', (e) => {
    const img = e.target;
    if (!(img instanceof HTMLImageElement)) return;

    // `dataset.replie` empêche la boucle si le remplacement échoue lui aussi.
    if (img.dataset.replie) return;
    img.dataset.replie = '1';
    img.src = window.POCHETTE_DEFAUT;
}, true);

// Une source vide ne déclenche pas d'erreur : on la traite à l'injection.
window.corrigerImagesVides = function (racine = document) {
    racine.querySelectorAll('img:not([data-replie])').forEach(img => {
        const src = img.getAttribute('src');
        if (!src || src === 'null' || src === 'undefined') {
            img.dataset.replie = '1';
            img.src = window.POCHETTE_DEFAUT;
        }
    });
};


// Ancre le player sur bureau : dans l'emplacement de la page s'il existe
// (dock du home), sinon dans la colonne globale de droite. Sur mobile,
// il retourne dans le footer.
function updatePlayerDock() {
    const player = document.getElementById('player');
    if (!player) return;

    const dock = document.getElementById('player-dock') || document.getElementById('player-aside');
    if (dock && window.matchMedia('(min-width: 1024px)').matches) {
        dock.appendChild(player);
    } else if (!player.closest('footer')) {
        document.querySelector('footer').insertBefore(player, document.getElementById('navbar'));
    }
}
window.addEventListener('resize', updatePlayerDock);
updatePlayerDock();

// Factorise la ré-exécution des scripts
function reinjectScripts(container) {
    container.querySelectorAll('script').forEach(oldScript => {
        if (oldScript.src && (
            oldScript.src.endsWith('player.js')
        )) {
            oldScript.remove();
            return;
        }
        const newScript = document.createElement('script');
        newScript.setAttribute('data-injected', ''); // marque le script pour pouvoir le supprimer
        if (oldScript.src) {
            newScript.src = oldScript.src;
        } else {
            newScript.textContent = oldScript.textContent;
        }
        document.body.appendChild(newScript);
        oldScript.remove();
    });
}

// Intercepte tous les formulaires POST du container injecté
function bindForms(container) {
    container.querySelectorAll('form[method="post"]').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const formData = new FormData(form);
            const target = form.dataset.action || routes[previousPage]; // data-action pour les actions externes

            const res = await fetch(target, {
                method: 'POST',
                body: formData
            });

            const contentType = res.headers.get('Content-Type') || '';

            if (contentType.includes('application/json')) {
                // Réponse JSON → action serveur (import, save, delete...)
                const json = await res.json();
                if (json.success) {
                    const redirect = form.dataset.redirect || previousPage;  // Utilise data-redirect si défini
                    showToast(json.message || 'Action réussie');
                    navigateTo(redirect); // Recharge la page courante proprement
                } else {
                    showToast(json.message || 'Une erreur est survenue', 'error');
                }
            } else {
                // Réponse HTML → affichage dans mainContent (comme import-form)
                mainContent.innerHTML = await res.text();
                window.corrigerImagesVides(mainContent);
                reinjectScripts(mainContent);
                bindForms(mainContent);
                bindDataPageLinks(mainContent);
            }
        });
    });
}

// Factorise le binding des liens data-page
function bindDataPageLinks(container) {
    container.querySelectorAll('a[data-page]').forEach(a => {
        a.addEventListener('click', (e) => {
            e.preventDefault();
            navigateTo(a.dataset.page);
        });
    });
}

// Charge la page sans reload
async function navigateTo(page) {
    // Un compte d'administration ne quitte pas sa section.
    if (window.UNISON_ADMIN && !String(page).startsWith('admin')) {
        page = 'admin';
    }

    // Permet de garder la page search pour la recherche et l'ajout dans les playlists
    if (page !== 'search') {
        sessionStorage.removeItem('search_playlist_id');
        sessionStorage.removeItem('search_playlist_name');

    }

    previousPage = page;

    // Vérification de l'existence
    const url = routes[page];
    if (!url) return;

    // Nettoyage des scripts injectés par la page précédente
    document.querySelectorAll('script[data-injected]').forEach(s => s.remove());
    window._searchInit = false;

    const extraParams = new URLSearchParams(location.search);
    extraParams.delete('page');

    // Injecte l'id de playlist depuis sessionStorage si on navigue vers la page playlist
    if (page === 'library/playlist') {
        const playlistId = sessionStorage.getItem('playlist_id');
        if (playlistId) extraParams.set('id', playlistId);
    }

    if (page === 'library/edit-playlist') {
        const playlistId = sessionStorage.getItem('edit_playlist_id');
        if (playlistId) extraParams.set('id', playlistId);
    }

    // Injecte l'id du titre ou de l'artiste pour les pages de détail
    if (page === 'library/titre') {
        const titreId = sessionStorage.getItem('titre_id');
        if (titreId) extraParams.set('id', titreId);
    }

    if (page === 'library/artiste') {
        const artisteId = sessionStorage.getItem('artiste_id');
        if (artisteId) extraParams.set('id', artisteId);
    }

    const fetchUrl = extraParams.toString() ? `${url}?${extraParams}` : url;

    // Récupère le code source et le renvoi dans la page active
    const res = await fetch(fetchUrl);

    const contentType = res.headers.get('Content-Type') || '';
    if (contentType.includes('application/json')) return;

    const html = await res.text();

    // Si le player est ancré dans la page courante, on le remet dans le
    // footer avant d'écraser le contenu pour ne pas le détruire
    const playerEl = document.getElementById('player');
    if (playerEl && mainContent.contains(playerEl)) {
        document.querySelector('footer').insertBefore(playerEl, document.getElementById('navbar'));
    }

    mainContent.innerHTML = html;
    window.corrigerImagesVides(mainContent);

    reinjectScripts(mainContent);
    bindForms(mainContent);
    bindDataPageLinks(mainContent);
    bindPlaylistAddLink(mainContent);
    if (window.initializeTrackContextMenus) window.initializeTrackContextMenus();
    if (window.initializePlaylistEditor) window.initializePlaylistEditor();
    updatePlayerDock();

    // Met à jour l'URL dans la barre d'adresse
    history.pushState({ page }, '', `?page=${page}`);
}

// Binding spécifique pour le lien "+" des playlists
function bindPlaylistAddLink(container) {
    container.querySelectorAll('a[data-page][data-playlist-id]').forEach(a => {
        a.addEventListener('click', (e) => {
            e.preventDefault();
            // Stocke l'id dans sessionStorage avant de naviguer
            sessionStorage.setItem('search_playlist_id', a.dataset.playlistId);
            sessionStorage.setItem('search_playlist_name', a.dataset.playlistName);
            navigateTo(a.dataset.page);
        });
    });
}

/*
 * Intercepte les liens de navigation de la barre — et eux seuls : `[data-page]`
 * est ce qui distingue une route interne d'un vrai lien (la déconnexion), que
 * preventDefault() rendrait inerte.
 */
document.querySelectorAll('#navbar a[data-page]').forEach(a => {
    a.addEventListener('click', (e) => {
        e.preventDefault();
        sessionStorage.removeItem('search_playlist_id');
        sessionStorage.removeItem('search_playlist_name');
        navigateTo(a.dataset.page);
    });
});
document.querySelectorAll('.redirect').forEach(a => {
    a.addEventListener('click', (e) => {
        e.preventDefault();
        navigateTo(a.dataset.page);
    });
});

/*
 * Lecture d'une ligne de titre, pour toutes les pages à la fois.
 *
 * Chaque page posait auparavant son propre onclick inline, recopié onze fois
 * et divergent (certaines mettaient à jour l'index de file, d'autres non).
 * La délégation est volontairement restreinte à [data-piste], l'attribut que
 * pose le rendu partagé : les résultats de recherche gèrent leurs propres
 * clics et ne doivent pas être happés ici.
 */
document.addEventListener('click', (e) => {
    const ligne = e.target.closest('.mini-song[data-piste]');
    if (!ligne) return;

    // Le menu contextuel gère lui-même son bouton « … ».
    if (e.target.closest('button')) return;

    const id = parseInt(ligne.dataset.trackId, 10);
    if (!Number.isInteger(id)) return;

    // Une ligne de la file d'attente déplace la lecture ; ailleurs, on lance
    // simplement le morceau sans toucher à la file.
    if (ligne.dataset.index !== undefined) {
        window.currentIndex = parseInt(ligne.dataset.index, 10);
    }

    if (typeof loadTrack === 'function') loadTrack(id);
});

// Bascule du mode d'affichage via les deux cercles du header :
// les deux allumés = contenu commun, seul le mien = contenu perso
const personsSwitch = document.getElementById('persons');
if (personsSwitch) {
    personsSwitch.addEventListener('click', async () => {
        const nextMode = personsSwitch.classList.contains('is-personal') ? 'mixed' : 'personal';

        // Bascule visuelle immédiate
        personsSwitch.classList.toggle('is-personal', nextMode === 'personal');
        personsSwitch.classList.toggle('is-mixed', nextMode === 'mixed');
        personsSwitch.setAttribute('aria-checked', nextMode === 'personal' ? 'true' : 'false');

        try {
            await fetch('actions/set_view_mode.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'mode=' + nextMode
            });
        } catch (e) {
            // En cas d'échec réseau, on recharge quand même la page courante
        }

        // Recharge la page courante pour appliquer le filtrage serveur
        navigateTo(previousPage || 'home');
    });
}

// Gère le bouton Retour du navigateur
window.addEventListener('popstate', (e) => {
    if (e.state?.page) navigateTo(e.state.page);
});

// Charge la bonne page au démarrage
/*
 * Page de démarrage. Un compte d'administration n'a pas de pages d'écoute :
 * il atterrit sur la gestion, et toute route hors de sa section y est
 * ramenée. Le serveur refuse déjà ces pages (exigerConnexion), ce garde-fou
 * évite simplement d'afficher un message d'erreur à la place du contenu.
 */
const demandee = new URLSearchParams(location.search).get('page');

function pageAutorisee(page) {
    if (!window.UNISON_ADMIN) return page && routes[page] ? page : 'home';
    return page && page.startsWith('admin') && routes[page] ? page : 'admin';
}

navigateTo(pageAutorisee(demandee));

// Popup de notification
window.showToast = function(message, type = 'success', duration = 60000) {
    const container = document.getElementById('toast-container');

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    /*
     * textContent, pas innerHTML : les messages viennent des réponses du
     * serveur, qui y recopient des noms de playlists et des titres de
     * morceaux — c'est-à-dire du texte venu de l'extérieur.
     */
    const texte = document.createElement('span');
    texte.className = 'toast-message';
    texte.textContent = message;

    const fermer = document.createElement('button');
    fermer.className = 'toast-close';
    fermer.setAttribute('aria-label', 'Fermer');
    fermer.textContent = '✕';

    toast.append(texte, fermer);

    container.appendChild(toast);

    // Déclenche l'animation d'entrée (besoin d'un frame pour la transition CSS)
    requestAnimationFrame(() => {
        requestAnimationFrame(() => toast.classList.add('show'));
    });

    // Fonction de fermeture réutilisée par le timer et la croix
    function dismiss() {
        toast.classList.remove('show');
        toast.classList.add('hide');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }

    // Fermeture auto après `duration` ms.
    // duration = 0 : le message reste jusqu'à ce qu'on le ferme. Réservé à ce
    // qu'il ne faut pas manquer, typiquement un échec d'import.
    const timer = duration > 0 ? setTimeout(dismiss, duration) : null;

    // Fermeture manuelle via la croix
    toast.querySelector('.toast-close').addEventListener('click', () => {
        if (timer) clearTimeout(timer);
        dismiss();
    });
}