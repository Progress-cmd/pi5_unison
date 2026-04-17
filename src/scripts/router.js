const mainContent = document.getElementById('main-content');
let previousPage = null; // garde en mémoire la page précédente

// Carte des pages
const routes = {
    'home':      'pages/home.php',
    'home/artists': 'pages/artists.php',
    'home/playlists': 'pages/playlists.php',
    'home/playlists/add_playlist': 'pages/add_playlist.php',
    'home/playlists/playlist': 'pages/playlist.php',
    'search':    'pages/search.php',
    'library': 'pages/library.php',
    'add':       'pages/add.php',
    'account':   'pages/account.php',
    'account/infos': 'pages/infos.php',
};

// Charge une page sans recharger
async function navigateTo(page) {
    if (page !== 'search' || (page === 'search' && previousPage !== 'home/playlists/playlist')) {
        sessionStorage.removeItem('search_playlist_id');
    }
    previousPage = page;

    const url = routes[page];
    if (!url) return;


    // Nettoie les scripts injectés par la page précédente
    document.querySelectorAll('script[data-injected]').forEach(s => s.remove());

    const res = await fetch(url);
    const html = await res.text();
    mainContent.innerHTML = html;

    // Ré-exécute les <script> injectés (fetch ne les exécute pas)
    mainContent.querySelectorAll('script').forEach(oldScript => {
        if (oldScript.src && oldScript.src.endsWith('footer.js')) {
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

    mainContent.querySelectorAll('a[data-page]').forEach(a => {
        a.addEventListener('click', (e) => {
            e.preventDefault();
            navigateTo(a.dataset.page);
        });
    });

    // Met à jour l'URL dans la barre d'adresse sans recharger
    history.pushState({ page }, '', `?page=${page}`);

    // Met à jour le lien actif dans la nav
    document.querySelectorAll('.mobil-sidebar a').forEach(a => {
        a.classList.toggle('active', a.dataset.page === page);
    });
}

// Intercepte tous les liens de la sidebar
document.querySelectorAll('.mobil-sidebar a').forEach(a => {
    a.addEventListener('click', (e) => {
        e.preventDefault();
        navigateTo(a.dataset.page);
    });
});

document.querySelectorAll('.more-bar').forEach(a => {
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