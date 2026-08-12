<?php
include_once "../includes/auth.php";
exigerConnexion(false);
?>

<article id="queue-bar" class="containers">
    <div class="head-bar">Liste d'attente</div>
    <div class="body-bar" id="queue-full">
        <!-- Rempli par JavaScript -->
    </div>
</article>

<script>
    (function() {
        const queueFull = document.getElementById('queue-full');

        function rendre() {

        if (!window.waitPlaylist || window.waitPlaylist.length === 0) {
            queueFull.innerHTML = '<div class="content"><em>Queue vide</em></div>';
            return;
        }

        queueFull.innerHTML = '';

        window.waitPlaylist.forEach((track, idx) => {
            const div = document.createElement('div');

            let className = 'content mini-song';
            if (idx === window.currentIndex) {
                className += ' selected';
            }

            div.className = className;
            div.setAttribute('data-track-id', track.id);
            div.onclick = () => {
                window.currentIndex = idx;
                loadTrack(track.id);
            };

            div.innerHTML = `
                <img src="${track.img}" class="song-img" alt="image">
                <div class="song-infos">
                    <div class="song-title">${track.title}</div>
                    <div class="song-artist">${track.artists_names}</div>
                </div>
                <div class="running badge">EN COURS</div>
                <button class="buttons material-symbols-outlined">more_vert</button>
            `;

            queueFull.appendChild(div);
        });

        // Scroll vers la chanson en cours
        const selected = document.querySelector('#queue-bar .selected');
        if (selected) {
            selected.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        }

        rendre();

        /*
         * Si l'application a été ouverte directement sur cette page, le player
         * récupère la file d'attente en arrière-plan : on se réaffiche quand
         * elle arrive, au lieu de rester sur « Queue vide ».
         */
        window.addEventListener('queueReady', rendre, { once: true });
    })();
</script>

<style>
    #queue-bar {
        display: flex;
        flex-direction: column;
        height: 100%;
        margin: 0;
        padding: 18px;
    }
    
    #queue-bar .head-bar {
        margin-bottom: 10px;
        flex-shrink: 0;
    }
    
    #queue-bar .body-bar {
        flex-grow: 1;
        overflow-y: auto;
    }
    
    #queue-bar .mini-song {
        margin: 0 !important;
    }
</style>
