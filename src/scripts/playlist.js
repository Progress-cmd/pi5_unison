(function() {
    const id = sessionStorage.getItem('playlist_id');
    if (!id) return;

    // Injecte l'id dans les attributs data pour que le PHP inline puisse s'en servir
    document.getElementById('playlist-content').dataset.id = id;

    fetch(`actions/get_playlist.php?id=${encodeURIComponent(id)}`)
        .then(r => r.json())
        .then(data => {
            // Met à jour le titre
            document.querySelector('#playlist-content .head-bar').innerHTML = `${data.name}<a href="?page=search&playlist_id=${id}" class="more-bar" data-page="search" data-playlist-id="${id}">+</a>`;

            document.querySelector('#playlist-content .head-bar a[data-page]').addEventListener('click', (e) => {
                e.preventDefault();
                sessionStorage.setItem('search_playlist_id', e.target.dataset.playlistId); // clé différente pour ne pas écraser playlist_id
                navigateTo(e.target.dataset.page);
            });

            // Génère les tracks
            const body = document.querySelector('#playlist-content .body-bar');
            body.innerHTML = data.tracks.map(track => `
                <button class="content" onclick="loadTrack(${track.id})">
                    <img src="${track.img}" class="mini-player-img" alt="image">
                    <div class="mini-proposition-info">
                        <div class="mini-title">${track.title}</div>
                        <div class="mini-artist">${track.artists_names ?? 'Artiste inconnu'}</div>
                    </div>
                </button>
            `).join('');
        });
})();