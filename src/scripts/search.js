(function() {
    const input = document.getElementById('search-entry');
    const resultsDiv = document.getElementById('search-results');
    // Lit directement depuis sessionStorage, pas besoin du DOM PHP
    const playlistId = sessionStorage.getItem('search_playlist_id');

    if (!input) return;

    // Affiche un bandeau si on est en mode ajout
    if (playlistId) {
        const banner = document.createElement('div');
        banner.id = 'playlist-context';
        banner.dataset.playlistId = playlistId;
        banner.textContent = `Ajout à la playlist #${playlistId}`;
        input.insertAdjacentElement('afterend', banner);
    }

    input.addEventListener('input', async () => {
        const query = input.value.trim();
        if (query.length < 2) {
            resultsDiv.innerHTML = '';
            return;
        }
        const formData = new FormData();
        formData.append('search-entry', query);
        const response = await fetch('actions/search.php', {
            method: 'POST',
            body: formData
        });
        const hits = await response.json();
        afficherResultats(hits);
    });

    function afficherResultats(hits) {
        if (hits.length === 0) {
            resultsDiv.innerHTML = '<p>Aucun résultat</p>';
            return;
        }
        resultsDiv.innerHTML = hits.map(hit => `
            <div class="result-item">
                <span>${hit.title_music || hit.name_artist}</span>
                ${playlistId && hit.id_music ? `<button onclick="addToPlaylist(${hit.id_music}, ${playlistId})"> +</button>` : ''}
            </div>
        `).join('');
    }
})();

function addToPlaylist(trackId, playlistId) {
    console.log('Envoi :', { trackId, playlistId });
    const formData = new FormData();
    formData.append('track_id', trackId);
    formData.append('playlist_id', playlistId);
    fetch('actions/add_to_playlist.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.text())
    .then(text => {
        const data = JSON.parse(text);
        const btn = document.querySelector(`button[onclick="addToPlaylist(${trackId}, ${playlistId})"]`);
        if (data.success) {
            btn.textContent = '✓';
            btn.disabled = true;
        }
        if (data.error) {
            btn.textContent = data.error === 'Déjà dans la playlist' ? '✓' : '✗';
            btn.disabled = true;
        }
    });
}