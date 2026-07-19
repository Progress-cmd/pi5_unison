(function() {
    const input = document.getElementById('search-entry');
    const form = document.getElementById('search-form');
    const resultsDiv = document.getElementById('search-results');
    const playlistId = sessionStorage.getItem('search_playlist_id');
    const playlistName = sessionStorage.getItem('search_playlist_name');

    // Échappe les caractères spéciaux HTML pour éviter les injections XSS
    const escape = str => str.replace(/[&<>"']/g, c => ({
        '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
    }[c]));

    if (!input) return;
    if (window._searchInit) return;
    window.addEventListener('beforeunload', () => { window._searchInit = false; }, { once: true });
    window._searchInit = true;

    if (playlistId) {
        if (!document.getElementById('playlist-context')) {
            const banner = document.createElement('div');
            banner.id = 'playlist-context';
            banner.dataset.playlistId = playlistId;
            banner.innerHTML = `Ajouter à <em>${escape(playlistName) || playlistId}</em>`;
            form.insertAdjacentElement('afterend', banner);
        }
    }

    // Debounce : attend 300ms après la dernière frappe avant d'envoyer la requête
    let debounceTimer;
    input.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(async () => {
            const query = input.value.trim();
            if (query.length < 2) {
                resultsDiv.innerHTML = 'Que souhaitez-vous rechercher ?';
                return;
            }
            const formData = new FormData();
            formData.append('search-entry', query);
            if (playlistId) formData.append('playlist_id', playlistId);
            try {
                const response = await fetch('actions/search.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                console.log(data);
                afficherResultats(data);
            } catch (err) {
                resultsDiv.innerHTML = '<p>Erreur lors de la recherche.</p>';
                window.showToast('Erreur lors de la recherche', 'error');
            }
        }, 300);
    });

    function afficherResultats(data) {
        const { musiques, artistes } = data;

        if (musiques.length === 0 && artistes.length === 0) {
            resultsDiv.innerHTML = '<p>Aucun résultat</p>';
            return;
        }

        const renderSection = (titre, hits, isMusic) => {
            if (hits.length === 0) return '';
            return `
            <div class="result-section">
                <h3>${titre}</h3>
                ${hits.map(hit => `
                    <div class="result-item" data-${isMusic ? 'titre' : 'artiste'}-id="${isMusic ? hit.id_music : hit.id_artist}" style="cursor: pointer;">
                        <span>${escape(isMusic ? hit.title_music : hit.name_artist)}</span>
                        ${playlistId && isMusic
                     ? `<button class="add-btn ${hit.in_playlist ? 'already-added' : ''}" 
                              data-track-id="${hit.id_music}" 
                              data-playlist-id="${playlistId}"
                              ${hit.in_playlist ? 'disabled' : ''}>
                          ${hit.in_playlist ? '✓' : '+'}
                       </button>`
                     : ''}
                    </div>
                `).join('')}
            </div>
        `;
        };

        resultsDiv.innerHTML =
            renderSection('Titres', musiques, true) +
            renderSection('Artistes', artistes, false);
    }

    resultsDiv.addEventListener('click', e => {
        const btn = e.target.closest('.add-btn');

        // Clic sur la ligne (hors bouton +) : ouvre la page de détail
        if (!btn) {
            const item = e.target.closest('.result-item');
            if (!item) return;

            if (item.dataset.titreId) {
                sessionStorage.setItem('titre_id', item.dataset.titreId);
                navigateTo('library/titre');
            } else if (item.dataset.artisteId) {
                sessionStorage.setItem('artiste_id', item.dataset.artisteId);
                navigateTo('library/artiste');
            }
            return;
        }

        const trackId = btn.dataset.trackId;
        const playlistId = btn.dataset.playlistId;
        btn.disabled = true;

        const formData = new FormData();
        formData.append('track_id', trackId);
        formData.append('playlist_id', playlistId);

        fetch('actions/add_to_playlist.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    btn.textContent = '✓';
                    btn.classList.add('already-added');
                } else {
                    btn.textContent = '✗';
                    btn.disabled = false;
                }
                if (data.message) {
                    window.showToast(data.message, data.success ? 'success' : 'error');
                }
            })
            .catch(() => {
                // Gère le cas où le fetch lui-même échoue (réseau, serveur down...)
                btn.textContent = '✗';
                btn.disabled = false;
                window.showToast("Erreur réseau", 'error');
            });
    });
})();