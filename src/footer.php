<footer>
    <div class="mobil-player" id="playerLink">
        <div class="retract">
            <div class="mini-controls">
                <button class="play-btn" aria-label="Play">
                    <span class="material-symbols-outlined">play_arrow</span>
                </button>
            </div>

            <div class="mini-player-info">
                <div class="mini-title">Midnight City</div>
                <div class="mini-artist">M83</div>
            </div>

            <div class="mini-controls">
                <button class="like-btn" aria-label="Like">
                    <span class="material-symbols-outlined">favorite</span>
                </button>
                <button class="next-btn" aria-label="Next">
                    <span class="material-symbols-outlined">skip_next</span>
                </button>
            </div>

            <div class="mini-progress-bar">
                <div class="mini-progress-current"></div>
            </div>
        </div>

        <!-- PLAYER EXPANDED (FULL SCREEN) -->
        <div class="extend">
            <!-- Top Bar -->
            <div class="expanded-top">
                <button class="close-player" id="closePlayer" aria-label="Fermer le player">
                    <span class="material-symbols-outlined">arrow_forward_ios</span>
                </button>
                <button class="more-player" aria-label="Plus d'options">
                    <span class="material-symbols-outlined">more_vert</span>
                </button>
            </div>

            <!-- Album Art -->
            <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop"
                 class="expanded-album-art" alt="Midnight City - M83">

            <!-- Top Controls -->
            <div class="expanded-top">
                <button class="add-btn" aria-label="Add">
                    <span class="material-symbols-outlined">add</span>
                </button>
                <button class="favorite-btn" aria-label="Favorite">
                    <span class="material-symbols-outlined">favorite</span>
                </button>
            </div>

            <!-- Progress Bar -->
            <div class="expanded-progress">
                <div class="expanded-progress-bar">
                    <div class="expanded-progress-current"></div>
                </div>
                <div class="expanded-time">
                    <span class="time-current">2:15</span>
                    <span class="time-total">5:44</span>
                </div>
            </div>

            <!-- Song Info -->
            <div class="expanded-info">
                <h2 class="expanded-title">Midnight City</h2>
                <p class="expanded-artist">M83</p>
            </div>

            <!-- Main Controls (Play/Pause, Previous, Next) -->
            <div class="expanded-controls">
                <button class="repeat-btn" aria-label="Repeat">
                    <span class="material-symbols-outlined">repeat</span>
                </button>
                <button class="prev-btn" aria-label="Précédent">
                    <span class="material-symbols-outlined">skip_previous</span>
                </button>
                <button class="play-btn expanded-play" aria-label="Play/Pause">
                    <span class="material-symbols-outlined">play_arrow</span>
                </button>
                <button class="next-btn" aria-label="Suivant">
                    <span class="material-symbols-outlined">skip_next</span>
                </button>
                <button class="shuffle-btn" aria-label="Shuffle">
                    <span class="material-symbols-outlined">shuffle</span>
                </button>
            </div>

            <!-- Bottom Controls -->
            <div class="expanded-bottom">
                <button class="queue-btn" aria-label="Afficher la queue">
                    <span class="material-symbols-outlined">queue_music</span>
                </button>
                <button class="volume-btn" aria-label="Volume">
                    <span class="material-symbols-outlined">volume_up</span>
                </button>
                <button class="menu-btn" aria-label="Menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
            </div>
        </div>
    </div>

    <script>
        const player = document.getElementById('playerLink');
        const closeBtn = document.getElementById('closePlayer');
        const extend = player.querySelector('.extend');

        player.addEventListener('click', function(e) {
            if (e.target.closest('button')) return;
            extend.classList.remove('closing');
            player.classList.add('expanded');
        });

        closeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            extend.classList.add('closing');
            extend.addEventListener('animationend', () => {
                player.classList.remove('expanded');
                extend.classList.remove('closing');
            }, { once: true });
        });
    </script>

    <nav class="mobil-sidebar">
        <div class="nav-home-area">
            <a class="sidebar-home" href="index.php">
                <span class="home-icon">🏠</span>
                <p>Accueil</p>
            </a>
        </div>

        <div class="nav-playlists-area">
            <a class="sidebar-playlists" href="playlists.php">
                <span class="playlists-icon">P</span>
                <p>Playlists</p>
            </a>
        </div>

        <div class="nav-search-area">
            <a class="sidebar-search" href="search.php">
                <span class="search-icon">🔍</span>
                <p>Rechercher</p>
            </a>
        </div>

        <div class="nav-add-area">
            <a class="sidebar-add" href="add.php">
                <span class="add-icon">+</span>
                <p>Ajouter</p>
            </a>
        </div>

        <div class="nav-account-area">
            <a class="sidebar-account" href="account.php">
                <span class="account-icon">👤</span>
                <p>Compte</p>
            </a>
        </div>
    </nav>
</footer>
</body>
</html>

<!--
<span class="material-symbols-outlined">
volume_down
</span>
<span class="material-symbols-outlined">
volume_off
</span>
<span class="material-symbols-outlined">
volume_up
</span>
<span class="material-symbols-outlined">
instant_mix
</span>