(function() {
    window.initializePlaylistEditor = function() {
        // Cible les boutons more_vert des playlists dans la page d'accueil/library
        document.querySelectorAll('.mini-playlist .playlist-controls .buttons.material-symbols-outlined').forEach(btn => {
            if (btn.textContent.includes('more')) {
                btn.removeEventListener('click', handleEditClick);
                btn.addEventListener('click', handleEditClick);
                btn.style.cursor = 'pointer';
            }
        });

        // Cible le bouton more_vert de la page playlist elle-même
        document.querySelectorAll('.edit-playlist-inline').forEach(btn => {
            btn.removeEventListener('click', handleEditClickInline);
            btn.addEventListener('click', handleEditClickInline);
        });
    };

    function handleEditClick(e) {
        e.stopPropagation();

        const playlist = e.target.closest('.mini-playlist');
        if (!playlist) return;

        const playlistId = playlist.dataset.id;
        if (!playlistId) return;

        sessionStorage.setItem('edit_playlist_id', playlistId);
        navigateTo('library/edit-playlist');
    }

    function handleEditClickInline(e) {
        e.stopPropagation();
        e.preventDefault();

        const playlistId = e.target.dataset.playlistId;
        if (!playlistId) return;

        sessionStorage.setItem('edit_playlist_id', playlistId);
        navigateTo('library/edit-playlist');
    }

    // Initialiser après le chargement
    setTimeout(() => window.initializePlaylistEditor(), 100);
})();
