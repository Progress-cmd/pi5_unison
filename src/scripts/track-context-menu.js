(function() {
    let contextMenu = null;
    let currentTrackId = null;
    let currentPlaylistId = null;

    function createContextMenu() {
        if (contextMenu) {
            contextMenu.remove();
            contextMenu = null;
        }

        contextMenu = document.createElement('div');
        contextMenu.id = 'track-context-menu';
        contextMenu.innerHTML = `
            <div class="context-menu-item" data-action="voir-titre">
                <span class="material-symbols-outlined">info</span>
                Voir le titre
            </div>
            <div class="context-menu-item" data-action="remove-track">
                <span class="material-symbols-outlined">delete</span>
                Supprimer de la playlist
            </div>
            <div class="context-menu-item" data-action="add-to-favorites">
                <span class="material-symbols-outlined">favorite</span>
                <span id="favorite-label">Ajouter aux favoris</span>
            </div>
        `;

        contextMenu.addEventListener('click', handleMenuClick);
        document.body.appendChild(contextMenu);

        return contextMenu;
    }

    async function showContextMenu(e, trackId, playlistId) {
        e.stopPropagation();
        e.preventDefault();

        currentTrackId = trackId;
        currentPlaylistId = playlistId;

        const menu = createContextMenu();
        menu.style.display = 'block';

        // Vérifie si c'est une chanson dans "Favorite Tracks"
        const miniSong = e.target.closest('.mini-song');
        const isFavoritesPlaylist = miniSong && (
            miniSong.classList.contains('favorite-playlist-song') ||
            miniSong.closest('#favorite-bar')
        );

        // Cache le bouton "Supprimer" si on est dans Favorite Tracks
        if (isFavoritesPlaylist) {
            const removeItem = menu.querySelector('[data-action="remove-track"]');
            if (removeItem) removeItem.style.display = 'none';
        }

        // Vérifie l'état du favori
        try {
            const res = await fetch(`actions/get_favorite.php?track_id=${trackId}`);
            const text = await res.text();
            const data = JSON.parse(text);

            if (data.status && data.liked) {
                document.getElementById('favorite-label').textContent = 'Retirer des favoris';
                document.querySelector('[data-action="add-to-favorites"] .material-symbols-outlined').style.fontVariationSettings = "'FILL' 1";
            }
        } catch (err) {
            console.error('Erreur getFavorite:', err);
        }

        let left = e.clientX;
        let top = e.clientY;

        // Ajuste la position pour rester dans la viewport
        setTimeout(() => {
            const rect = menu.getBoundingClientRect();
            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;

            if (rect.right > viewportWidth) {
                left = viewportWidth - rect.width - 10;
            }
            if (rect.bottom > viewportHeight) {
                top = viewportHeight - rect.height - 10;
            }

            menu.style.left = Math.max(10, left) + 'px';
            menu.style.top = Math.max(10, top) + 'px';
        }, 0);

        menu.style.left = left + 'px';
        menu.style.top = top + 'px';

        setTimeout(() => {
            document.addEventListener('click', hideContextMenu, { once: true });
        }, 0);
    }

    function hideContextMenu() {
        if (contextMenu) {
            contextMenu.style.display = 'none';
        }
    }

    async function handleMenuClick(e) {
        const item = e.target.closest('.context-menu-item');
        if (!item) return;

        const action = item.dataset.action;
        hideContextMenu();

        switch (action) {
            case 'voir-titre':
                sessionStorage.setItem('titre_id', currentTrackId);
                navigateTo('library/titre');
                break;
            case 'remove-track':
                if (currentPlaylistId) {
                    await removeTrackFromPlaylist(currentTrackId, currentPlaylistId);
                }
                break;
            case 'add-to-favorites':
                await toggleFavorite(currentTrackId);
                break;
        }
    }

    async function removeTrackFromPlaylist(trackId, playlistId) {
        try {
            const res = await fetch('actions/remove_track_from_playlist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `track_id=${trackId}&playlist_id=${playlistId}`
            });
            const data = await res.json();

            if (data.success) {
                window.showToast('Chanson supprimée');
                location.reload();
            } else {
                window.showToast('Erreur: ' + data.message, 'error');
            }
        } catch (e) {
            window.showToast('Erreur réseau', 'error');
        }
    }

    async function toggleFavorite(trackId) {
        try {
            const res = await fetch(`actions/toggle_favorite.php?track_id=${trackId}`);
            const data = await res.json();

            if (data.success) {
                window.showToast(data.message, 'success');
                // Recharge la page si on est sur la bibliothèque
                const mainContent = document.querySelector('#main-content');
                if (mainContent && mainContent.innerHTML.includes('favorite-bar')) {
                    setTimeout(() => location.reload(), 500);
                }
            } else {
                window.showToast('Erreur', 'error');
            }
        } catch (e) {
            window.showToast('Erreur réseau', 'error');
        }
    }

    window.initializeTrackContextMenus = function() {
        // Cible SEULEMENT les boutons more_vert des chansons (.mini-song), pas des playlists
        document.querySelectorAll('.mini-song .buttons.material-symbols-outlined').forEach(btn => {
            if (btn.textContent.includes('more')) {
                btn.removeEventListener('click', handleMoreClick);
                btn.addEventListener('click', handleMoreClick);
                btn.style.cursor = 'pointer';
            }
        });
    };

    function handleMoreClick(e) {
        const miniSong = e.target.closest('.mini-song');
        if (!miniSong) return;

        const trackId = miniSong.dataset.trackId;
        const playlistId = miniSong.closest('[data-playlist-id]')?.dataset.playlistId;

        showContextMenu(e, trackId, playlistId);
    }

    // Initialiser au démarrage
    setTimeout(() => window.initializeTrackContextMenus(), 100);
})();
