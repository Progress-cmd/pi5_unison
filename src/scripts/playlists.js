(function() {
    // Délègue le clic sur tous les .content qui ont un data-id
    const body = document.querySelector('.playlist');
    if (!body) return;

    body.addEventListener('click', (e) => {
        const card = e.target.closest('.content[data-id]');
        if (!card) return;

        const id = card.dataset.id;

        // Stocke l'id pour que la page playlist.php puisse le récupérer
        sessionStorage.setItem('playlist_id', id);

        // Navigue via le routeur existant
        navigateTo('home/playlists/playlist');
    });
})();