(function() {
    const player = document.getElementById('player');
    const closeBtn = document.getElementById('close-button');
    const extend = document.getElementById('extend');
    let currentTrackId = null;

    // --- Audio setup ---
    const audio = new Audio();

    // Demande la permission d'accès aux appareils audio une seule fois au démarrage
    navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
        stream.getTracks().forEach(track => track.stop());
    }).catch(err => {
        console.error('Permission audio refusée:', err);
    });

    // --- Charge une piste par ID et met à jour le player ---
    async function loadTrack(id, autoplay = true) {
        currentTrackId = id;
        const res = await fetch(`actions/getTrack.php?id=${id}`);
        const track = await res.json();

        if (!track) return;

        audio.src = track.src;

        document.querySelector('#retract .title-info').textContent = track.title;
        document.querySelector('#retract  .artist-info').textContent = track.artist;
        document.querySelector('#extend .title-info').textContent = track.title;
        document.querySelector('#extend  .artist-info').textContent = track.artist;
        document.getElementById('player-img').src = track.img;
        document.getElementById('player-img').alt = `${track.title} - ${track.artist}`;

        document.querySelector('.player-progress_current').style.width = '0%';
        document.querySelector('.time-current').textContent = '0:00';
        document.querySelector('.time-total').textContent = formatTime(track.duration);

        audio.load();
        if (autoplay) {
            audio.addEventListener('canplay', () => {
                audio.play().catch(() => {});
            }, { once: true });
        }
        updateSelected();
    }

    window.loadTrack = loadTrack;

    window.addEventListener('playlistReady', (e) => {
        const playlist = e.detail.playlist;
        if (!playlist || playlist.length === 0) return;

        window.waitPlaylist = playlist;

        if (!currentTrackId) {
            loadTrack(playlist[0]['id'], false);
            window.currentIndex = 0;
        } else {
            const idx = playlist.findIndex(t => t.id == currentTrackId);
            window.currentIndex = idx !== -1 ? idx : 0;
            updateSelected();
        }
    });

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
        extend.addEventListener('animationend', () => {
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

    audio.addEventListener('timeupdate', () => {
        if (!audio.duration) return;
        const pct = (audio.currentTime / audio.duration) * 100;

        const miniBar = document.querySelector('#retract .player-progress_current');
        if (miniBar) miniBar.style.width = pct + '%';

        const expBar = document.querySelector('#extend .player-progress_current');
        if (expBar) expBar.style.width = pct + '%';

        document.querySelector('.time-current').textContent = formatTime(audio.currentTime);
        document.querySelector('.time-total').textContent = formatTime(audio.duration);
    });

    function formatTime(s) {
        if (isNaN(s)) return '0:00';
        const m = Math.floor(s / 60);
        const sec = Math.floor(s % 60).toString().padStart(2, '0');
        return `${m}:${sec}`;
    }

    document.querySelectorAll('.player-progress_bar').forEach(bar => {
        bar.addEventListener('click', function(e) {
            if (!audio.duration) return;
            const rect = this.getBoundingClientRect();
            const ratio = (e.clientX - rect.left) / rect.width;
            audio.currentTime = ratio * audio.duration;
        });
    });

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

    // --- Fin de piste avec gestion du repeat ---
    let repeatMode = 0;
    audio.addEventListener('ended', () => {
        if (repeatMode === 1) {
            // Rejoue la même piste une fois
            audio.currentTime = 0;
            audio.play();
            repeatMode = 0;
            document.getElementById('repeat-button').textContent = 'repeat';
            document.getElementById('repeat-button').style.color = '';
        } else if (repeatMode === 2) {
            // Boucle infinie - audio.loop gère ça
            return;
        } else if (window.waitPlaylist && window.currentIndex < window.waitPlaylist.length - 1) {
            window.currentIndex++;
            loadTrack(window.waitPlaylist[window.currentIndex].id);
            updateSelected();
        } else {
            updatePlayBtns();
        }
    });

    function updateSelected() {
        const items = document.querySelectorAll('.mini-song');
        items.forEach((el, i) => {
            el.classList.toggle('selected', i === window.currentIndex);
        });
        getFavorite(currentTrackId);

        const selected = document.querySelector('.mini-song.selected');
        if (selected) {
            selected.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    document.querySelectorAll('.favorite-button').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();

            const trackId = currentTrackId;
            if (!trackId) return;
            try {
                const res = await fetch(`actions/toggle_favorite.php?track_id=${trackId}`);
                const data = await res.json();
                if (data.success) {
                    const active = data.liked;
                    document.querySelectorAll('.favorite-button').forEach(b => {
                        b.classList.toggle('active', active);
                        b.style.color = active ? '#C8593A' : '';
                        b.style.fontVariationSettings = active ? "'FILL' 1" : "'FILL' 0";
                    });

                    // Recharge la page si on est sur la bibliothèque
                    const mainContent = document.querySelector('#main-content');
                    if (mainContent && mainContent.innerHTML.includes('favorite-bar')) {
                        setTimeout(() => location.reload(), 500);
                    }
                }
                if (data.message) {
                    window.showToast(data.message, data.success ? 'success' : 'error');
                }
            } catch {
                window.showToast('Erreur réseau', 'error');
            }
        });
    });

    // ========== BOUTONS DU PLAYER ==========

    // --- SHUFFLE - Mélanger la queue ---
    document.getElementById('rand-button').addEventListener('click', (e) => {
        e.stopPropagation();
        const btn = e.currentTarget;

        if (!window.waitPlaylist || window.waitPlaylist.length < 2) {
            window.showToast('Queue trop courte pour mélanger', 'error');
            return;
        }

        const current = window.waitPlaylist[0];
        const rest = window.waitPlaylist.slice(1);

        for (let i = rest.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [rest[i], rest[j]] = [rest[j], rest[i]];
        }

        window.waitPlaylist = [current, ...rest];

        // Met à jour le DOM de la queue
        const queueBody = document.querySelector('#queue-bar .body-bar');
        if (queueBody) {
            queueBody.innerHTML = '';
            window.waitPlaylist.forEach((track, idx) => {
                const div = document.createElement('div');
                div.className = 'content mini-song' + (idx === window.currentIndex ? ' selected' : '');
                div.setAttribute('data-track-id', track.id);
                div.onclick = () => { window.currentIndex = idx; loadTrack(track.id); };
                div.innerHTML = `
                    <img src="${track.img}" class="song-img" alt="image">
                    <div class="song-infos">
                        <div class="song-title">${track.title}</div>
                        <div class="song-artist">${track.artists_names}</div>
                    </div>
                    <div class="running badge">EN COURS</div>
                    <button class="buttons material-symbols-outlined">more_vert</button>
                `;
                queueBody.appendChild(div);
            });
        }

        btn.classList.add('active');
        btn.style.color = '#C8593A';
        window.showToast('Queue mélangée! 🔀');
    });

    // --- REPEAT - 3 modes: repeat → repeat_one → all_inclusive ---
    document.getElementById('repeat-button').addEventListener('click', (e) => {
        e.stopPropagation();
        const btn = e.currentTarget;

        repeatMode = (repeatMode + 1) % 3;

        const displays = ['repeat', 'repeat_one', 'all_inclusive'];
        const colors = ['', '#C8593A', '#C8593A'];

        btn.textContent = displays[repeatMode];
        btn.style.color = colors[repeatMode];
        btn.classList.toggle('active', repeatMode > 0);

        audio.loop = (repeatMode === 2);

    });

    // --- VOLUME - 3 niveaux: 0% → 50% → 100% ---
    let volumeLevel = 2;
    document.getElementById('volume-button').addEventListener('click', (e) => {
        e.stopPropagation();
        const btn = e.currentTarget;

        volumeLevel = (volumeLevel + 1) % 3;
        const volumes = [0, 0.5, 1];
        const icons = ['volume_off', 'volume_down', 'volume_up'];

        audio.volume = volumes[volumeLevel];
        btn.textContent = icons[volumeLevel];
    });

    // --- ADD - Ajouter à une playlist ---
    async function showPlaylistModal() {
        if (!currentTrackId) {
            window.showToast('Aucune chanson en cours', 'error');
            return;
        }

        try {
            const res = await fetch('actions/get_playlists.php');
            const data = await res.json();

            if (!data.success || !data.playlists.length) {
                window.showToast('Aucune playlist disponible', 'error');
                return;
            }

            // Crée le modal
            const modal = document.createElement('div');
            modal.id = 'playlist-modal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1000;
            `;

            const content = document.createElement('div');
            content.style.cssText = `
                background: white;
                border-radius: 12px;
                padding: 20px;
                width: 90%;
                max-width: 400px;
                max-height: 60vh;
                overflow-y: auto;
            `;

            content.innerHTML = `
                <h2 style="margin-top: 0; margin-bottom: 20px;">Ajouter à une playlist</h2>
                <div id="playlist-list"></div>
            `;

            const playlistList = content.querySelector('#playlist-list');
            data.playlists.forEach(playlist => {
                const item = document.createElement('div');
                item.style.cssText = `
                    padding: 12px;
                    margin-bottom: 8px;
                    background: #f5f5f5;
                    border-radius: 8px;
                    cursor: pointer;
                    transition: background 0.2s;
                `;
                item.textContent = playlist.name;
                item.onmouseover = () => item.style.background = '#e0e0e0';
                item.onmouseout = () => item.style.background = '#f5f5f5';
                item.onclick = async () => {
                    await addToPlaylist(currentTrackId, playlist.id, playlist.name);
                    modal.remove();
                };
                playlistList.appendChild(item);
            });

            modal.appendChild(content);
            modal.onclick = (e) => {
                if (e.target === modal) modal.remove();
            };
            document.body.appendChild(modal);
        } catch (e) {
            window.showToast('Erreur: ' + e.message, 'error');
        }
    }

    async function addToPlaylist(trackId, playlistId, playlistName) {
        try {
            const res = await fetch('actions/add_to_playlist.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `track_id=${trackId}&playlist_id=${playlistId}`
            });
            const data = await res.json();
            if (data.success) {
                window.showToast(`Ajouté à "${playlistName}" ✓`);
            } else {
                window.showToast('Erreur lors de l\'ajout', 'error');
            }
        } catch (e) {
            window.showToast('Erreur: ' + e.message, 'error');
        }
    }

    document.getElementById('add-button').addEventListener('click', (e) => {
        e.stopPropagation();
        showPlaylistModal();
    });

    // --- MORE - Menu d'actions supplémentaires ---
    async function showSettingsModal() {
        const modal = document.createElement('div');
        modal.id = 'settings-modal';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        `;

        const content = document.createElement('div');
        content.style.cssText = `
            background: white;
            border-radius: 12px;
            padding: 20px;
            width: 90%;
            max-width: 400px;
        `;

        content.innerHTML = `
            <h2 style="margin-top: 0; margin-bottom: 20px;">Paramètres audio</h2>
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-weight: bold;">Volume: <span id="volume-value">100</span>%</label>
                <input type="range" id="volume-slider" min="0" max="100" value="100" style="width: 100%; cursor: pointer;">
            </div>
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-weight: bold;">Sortie audio:</label>
                <select id="audio-device-select" style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #ccc;">
                    <option value="">Détection en cours...</option>
                </select>
            </div>
            <button id="close-settings" style="width: 100%; padding: 10px; background: #C8593A; color: white; border: none; border-radius: 4px; cursor: pointer;">Fermer</button>
        `;

        const slider = content.querySelector('#volume-slider');
        const volumeValue = content.querySelector('#volume-value');
        const deviceSelect = content.querySelector('#audio-device-select');
        const closeBtn = content.querySelector('#close-settings');

        // Initialise le slider avec le volume actuel
        slider.value = Math.round(audio.volume * 100);
        volumeValue.textContent = slider.value;

        // Mise à jour du volume
        slider.oninput = () => {
            audio.volume = slider.value / 100;
            volumeValue.textContent = slider.value;
        };

        // Fonction pour énumérer les appareils
        async function enumerateAudioDevices() {
            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                const audioDevices = devices.filter(device => device.kind === 'audiooutput');

                if (audioDevices.length > 0) {
                    deviceSelect.innerHTML = '';
                    audioDevices.forEach(device => {
                        const option = document.createElement('option');
                        option.value = device.deviceId;
                        option.textContent = device.label || `Appareil audio ${device.deviceId.substring(0, 8)}`;
                        deviceSelect.appendChild(option);
                    });

                    // Pré-sélectionne l'appareil actuellement utilisé
                    deviceSelect.value = audio.sinkId || '';

                    // Si aucun sinkId, sélectionne le premier
                    if (!audio.sinkId && audioDevices.length > 0) {
                        deviceSelect.value = audioDevices[0].deviceId;
                    }
                } else {
                    deviceSelect.innerHTML = '<option>Aucun appareil détecté</option>';
                }
            } catch (err) {
                console.error('Erreur énumération appareils:', err);
                deviceSelect.innerHTML = '<option>Erreur énumération appareils</option>';
            }
        }

        // Change l'appareil audio quand on sélectionne
        deviceSelect.onchange = async () => {
            if (deviceSelect.value) {
                try {
                    if (audio.setSinkId) {
                        await audio.setSinkId(deviceSelect.value);
                        window.showToast('Appareil audio changé', 'success');
                    } else {
                        window.showToast('setSinkId non supporté', 'error');
                    }
                } catch (err) {
                    console.error('Erreur setSinkId:', err);
                    window.showToast('Erreur: ' + err.message, 'error');
                }
            }
        };

        // Énumère les appareils
        await enumerateAudioDevices();

        closeBtn.onclick = () => modal.remove();
        modal.onclick = (e) => {
            if (e.target === modal) modal.remove();
        };

        modal.appendChild(content);
        document.body.appendChild(modal);
    }

    document.getElementById('menu-button').addEventListener('click', (e) => {
        e.stopPropagation();
        showSettingsModal();
    });

    // --- QUEUE - Fermer le player et naviguer ---
    const queueBtn = document.getElementById('queue-button');
    if (queueBtn) {
        queueBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();

            // Ferme le player extended
            extend.classList.remove('expanded');
            extend.classList.add('closing');
            extend.addEventListener('animationend', () => {
                extend.classList.remove('closing');
                extend.style.visibility = 'hidden';
                // Navigue après la fermeture du player
                navigateTo('player/queue');
            }, { once: true });
        });
    }

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

    window.getFavorite = getFavorite;
})();
