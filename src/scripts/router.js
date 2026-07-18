const mainContent = document.getElementById('main-content'); // Pointe vers le contenu de la page
let previousPage = null; // Garde en mémoire la page précédente

// Cartes des pages
const routes = {
    'home':     'pages/home.php',
    'home/queue': '',
    'library':  'pages/library.php',
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
};


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

    const fetchUrl = extraParams.toString() ? `${url}?${extraParams}` : url;

    // Récupère le code source et le renvoi dans la page active
    const res = await fetch(fetchUrl);

    const contentType = res.headers.get('Content-Type') || '';
    if (contentType.includes('application/json')) return;

    const html = await res.text();
    mainContent.innerHTML = html;

    reinjectScripts(mainContent);
    bindForms(mainContent);
    bindDataPageLinks(mainContent);
    bindPlaylistAddLink(mainContent);
    if (window.initializeTrackContextMenus) window.initializeTrackContextMenus();
    if (window.initializePlaylistEditor) window.initializePlaylistEditor();

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

// Intercepte tous les liens de la sidebar
document.querySelectorAll('#navbar a').forEach(a => {
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

// Gère le bouton Retour du navigateur
window.addEventListener('popstate', (e) => {
    if (e.state?.page) navigateTo(e.state.page);
});

// Charge la bonne page au démarrage
const startPage = new URLSearchParams(location.search).get('page') || 'home';
navigateTo(startPage);

// Popup de notification
window.showToast = function(message, type = 'success', duration = 60000) {
    const container = document.getElementById('toast-container');

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <span class="toast-message">${message}</span>
        <button class="toast-close" aria-label="Fermer">✕</button>
    `;

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

    // Fermeture auto après `duration` ms
    const timer = setTimeout(dismiss, duration);

    // Fermeture manuelle via la croix
    toast.querySelector('.toast-close').addEventListener('click', () => {
        clearTimeout(timer);
        dismiss();
    });
}