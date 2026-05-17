(function() {
    const player = document.getElementById('player');
    const closeBtn = document.getElementById('close-button');
    const extend = document.getElementById('extend');
    let currentTrackId = null;

    // --- Audio setup ---
    const audio = new Audio();

    // --- Charge une piste par ID et met à jour le player ---
    async function loadTrack(id, autoplay = true) {
        currentTrackId = id;
        const res = await fetch(`actions/getTrack.php?id=${id}`);
        const track = await res.json();

        if (!track) return;

        // Met à jour l'audio
        audio.src = track.src;

        // Met à jour l'UI du player
        document.querySelector('#retract .title-info').textContent = track.title;
        document.querySelector('#retract  .artist-info').textContent = track.artist;
        document.querySelector('#extend .title-info').textContent = track.title;
        document.querySelector('#extend  .artist-info').textContent = track.artist;
        document.getElementById('player-img').src = track.img;
        document.getElementById('player-img').alt = `${track.title} - ${track.artist}`;

        // Reset barres de progression
        document.querySelector('.player-progress_current').style.width = '0%';
        document.querySelector('.time-current').textContent = '0:00';
        document.querySelector('.time-total').textContent = formatTime(track.duration);

        // Charge et lit l'audio
        audio.load();
        if (autoplay) {
            audio.addEventListener('canplay', () => {
                audio.play().catch(() => {});
            }, { once: true });
        }
        updateSelected();
    }

    // --- Expose globalement pour appel depuis les pages ---
    window.loadTrack = loadTrack;

    // Charge le premier titre de la playlist au démarrage
    window.addEventListener('playlistReady', (e) => {
        const playlist = e.detail.playlist;
        if (!playlist || playlist.length === 0) return;

        // Met à jour la playlist globale pour next/prev
        window.waitPlaylist = playlist;

        // Ne recharge la piste que si rien n'est en cours de lecture
        if (!currentTrackId) {
            loadTrack(playlist[0]['id'], false);
            window.currentIndex = 0;
        } else {
            // Retrouve l'index de la piste en cours dans la nouvelle playlist
            const idx = playlist.findIndex(t => t.id == currentTrackId);
            window.currentIndex = idx !== -1 ? idx : 0;
            updateSelected();
        }
    });

    // --- Expand / Collapse ---
    player.addEventListener('click', function(e) {
        if (e.target.closest('button, .player-progress_bar')) return;
        extend.style.visibility = '';
        extend.classList.remove('closing');
        extend.classList.add('expanded');
    });

    closeBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        extend.classList.remove('expanded');
        extend.classList.add('closing');
        extend.addEventListener('animationend', () => { // Attend que l'animation soit finie avant de disparaitre
            extend.classList.remove('closing');
            extend.style.visibility = 'hidden';
        }, { once: true });
    });

    function updatePlayBtns() {
        const icon = audio.paused ? 'play_arrow' : 'pause';
        document.querySelectorAll('.play-button').forEach(el => {
            el.textContent = icon;
        });
    }

    document.querySelectorAll('.play-button').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            audio.paused ? audio.play() : audio.pause();
        });
    });

    audio.addEventListener('play', updatePlayBtns);
    audio.addEventListener('pause', updatePlayBtns);

    // --- Progress bars ---
    audio.addEventListener('timeupdate', () => {
        if (!audio.duration) return;
        const pct = (audio.currentTime / audio.duration) * 100;

        // Mini player
        const miniBar = document.querySelector('#retract .player-progress_current');
        if (miniBar) miniBar.style.width = pct + '%';

        // Expanded player
        const expBar = document.querySelector('#extend .player-progress_current');
        if (expBar) expBar.style.width = pct + '%';

        // Temps affiché
        document.querySelector('.time-current').textContent = formatTime(audio.currentTime);
        document.querySelector('.time-total').textContent = formatTime(audio.duration);
    });

    function formatTime(s) {
        if (isNaN(s)) return '0:00';
        const m = Math.floor(s / 60);
        const sec = Math.floor(s % 60).toString().padStart(2, '0');
        return `${m}:${sec}`;
    }

    // --- Clic sur la barre de progression ---
    document.querySelectorAll('.player-progress_bar').forEach(bar => {
        bar.addEventListener('click', function(e) {
            if (!audio.duration) return;
            const rect = this.getBoundingClientRect();
            const ratio = (e.clientX - rect.left) / rect.width;
            audio.currentTime = ratio * audio.duration;
        });
    });

    // --- Next / Prev ---
    document.querySelectorAll('.next-button').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (window.waitPlaylist && window.currentIndex < window.waitPlaylist.length - 1) {
                window.currentIndex++;
                loadTrack(window.waitPlaylist[window.currentIndex].id);
                updateSelected();
            }
        });
    });

    document.querySelectorAll('.prev-button').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (window.waitPlaylist && window.currentIndex > 0) {
                window.currentIndex--;
                loadTrack(window.waitPlaylist[window.currentIndex].id);
                updateSelected();
            }
        });
    });

    // Passe automatiquement au suivant en fin de piste
    audio.addEventListener('ended', () => {
        if (window.waitPlaylist && window.currentIndex < window.waitPlaylist.length - 1) {
            window.currentIndex++;
            loadTrack(window.waitPlaylist[window.currentIndex].id);
            updateSelected();
        } else {
            updatePlayBtns();
        }
    });

    // --- Met à jour la div "selected" dans la liste d'attente ---
    function updateSelected() {
        const items = document.querySelectorAll('.mini-song');
        items.forEach((el, i) => {
            el.classList.toggle('selected', i === window.currentIndex);
        });
        getFavorite(currentTrackId);

        // Scroll vers le titre en cours
        const selected = document.querySelector('.mini-song.selected');
        if (selected) {
            selected.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    // --- Like / Favorite ---
    document.querySelectorAll('.favorite-button').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();

            const trackId = currentTrackId;
            // Récupère l'id depuis l'URL audio — adapte si tu stockes l'id autrement
            if (!trackId) return;
            try {
                const res = await fetch(`actions/toggle_favorite.php?track_id=${trackId}`);
                const data = await res.json();
                if (data.success) {
                    const active = data.liked;
                    btn.classList.toggle('active', active);
                    btn.style.color = active ? '#C8593A' : '';
                    btn.style.fontVariationSettings = active ? "'FILL' 1" : "'FILL' 0";
                }
                if (data.message) {
                    window.showToast(data.message, data.success ? 'success' : 'error');
                }
            } catch {
                window.showToast('Erreur réseau', 'error');
            }
        });
    });

    // --- Shuffle ---
    document.getElementById('rand-button').addEventListener('click', (e) => {
        e.stopPropagation();
        const btn = e.currentTarget;
        btn.classList.toggle('active');
        btn.style.color = btn.classList.contains('active') ? '#C8593A' : '';
    });

    // --- Repeat ---
    document.getElementById('repeat-button').addEventListener('click', (e) => {
        e.stopPropagation();
        const btn = e.currentTarget;
        btn.classList.toggle('active');
        audio.loop = btn.classList.contains('active');
        btn.style.color = audio.loop ? '#C8593A' : '';
    });

    // --- Volume ---
    document.getElementById('volume-button').addEventListener('click', (e) => {
        e.stopPropagation();
        audio.muted = !audio.muted;
        const icon = e.currentTarget.querySelector('.material-symbols-outlined');
        icon.textContent = audio.muted ? 'volume_off' : 'volume_up';
    });

    // --- Fin de piste ---
    audio.addEventListener('ended', () => {
        updatePlayBtns();
        document.querySelector('#retract .player-progress_current').style.width = '0%';
        document.querySelector('#extend .player-progress_current').style.width = '0%';
    });

    // --- Cherche si dans Favorite ---
    async function getFavorite(trackId) {
        const res = await fetch(`actions/get_favorite.php?track_id=${trackId}`);
        const text = await res.text();
        const data = JSON.parse(text);

        if (data.status) {
            const active = data.liked;
            document.querySelectorAll('.favorite-button').forEach(btn => {
                btn.classList.toggle('active', active);
                btn.style.color = active ? '#C8593A' : '';
                btn.style.fontVariationSettings = active ? "'FILL' 1" : "'FILL' 0";
            });
        }
    }
})();































