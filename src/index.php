<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

session_write_close();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="styles/accueil.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500;600&display=swap">
    <title>Unison - Accueil</title>
</head>
<body>
<header>
    <?php
    $time = date("H");
    $salutation = ($time <= 17) ? "Bonjour" : "Bonsoir";
    if ($time >= 7 && $time < 12) {
        $moment = "ce matin";
    }
    else if ($time >= 12 && $time < 14) {
        $moment = "ce midi";
    }
    else if ($time >= 14 && $time < 18) {
        $moment = "cette après-midi";
    }
    else if ($time >= 18 && $time < 22) {
        $moment = "ce soir";
    }
    else {
        $moment = "cette nuit";
    }
    ?>
    <div class="accueil-headline"><?= $salutation ?>,<br><em><?= $_SESSION['user']['username'] ?></em></div>
    <p class="accueil-sub">Que voulez-vous écouter <?= $moment ?> ?</p>
    <div class="present-moi" style="border: <?php if ($_SESSION['user']['username']=='Francis') { echo "#C8593A"; } else { echo "#4A7C99"; }?> 2px solid;">OO</div>
    <div class="present-toi" style="border: <?php if ($_SESSION['user']['username']=='Cassandre') { echo "#C8593A"; } else { echo "#4A7C99"; }?> 2px solid;">OO</div>
</header>

<main id="main-content">
</main>

<footer>
    <?php
    include_once "includes/config.php";
    $pdo = Config::getConnection();

    session_start();

    $req = $pdo->prepare("SELECT id FROM tracks ORDER BY RAND()");
    $req->execute();
    $tracks = $req->fetchAll();

    $req = $pdo->prepare("SELECT id FROM playlists WHERE name = 'Wait Tracks' AND `created-by_id` = :user_id");
    $req->execute([':user_id' => $_SESSION['user']['id']]);
    $playlist = $req->fetch();

    $req = $pdo->prepare("DELETE FROM track__playlist WHERE playlist_id = :pid");
    $req->execute([':pid' => $playlist['id'] ?? 0]);

    $values = [];
    $params = [];
    foreach ($tracks as $i => $trackId) {
        $values[] = "(:p$i, :t$i, :pos$i)";
        $params[":p$i"] = $playlist['id'];
        $params[":t$i"] = $trackId['id'];
        $params[":pos$i"] = $i + 1;
    }

    if (!empty($values)) {
        $req = $pdo->prepare("INSERT INTO track__playlist (playlist_id, track_id, position) VALUES " . implode(', ', $values));
        $req->execute($params);
    }


    $req = $pdo->prepare("SELECT tracks.id
                                FROM playlists
                                LEFT JOIN track__playlist ON playlist_id = playlists.id
                                LEFT JOIN tracks ON track_id = tracks.id
                                WHERE playlists.name = 'Wait Tracks' AND playlists.`created-by_id` = :user_id
                                ORDER BY track__playlist.position
                                ");

    $req->execute([':user_id' => $_SESSION['user']['id']]);
    $allTrackIds = $req->fetchAll(PDO::FETCH_COLUMN);

    session_write_close();
    ?>
    <script>
        window.waitPlaylist = <?= json_encode($allTrackIds) ?>;
        window.currentIndex = 0;
    </script>
    <div class="mobil-player" id="playerLink">
        <div class="retract">
            <div class="mini-controls">
                <button class="play-btn" aria-label="Play">
                    <span class="material-symbols-outlined">play_arrow</span>
                </button>
            </div>

            <div class="mini-player-info">
                <div class="mini-title" id="title"></div>
                <div class="mini-artist" id="artist"></div>
            </div>

            <div class="mini-controls">
                <button class="favorite-btn" aria-label="Favorite">
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
            <img src="" class="expanded-album-art" alt="">

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
                    <span class="time-current"></span>
                    <span class="time-total"></span>
                </div>
            </div>

            <!-- Song Info -->
            <div class="expanded-info">
                <h2 class="expanded-title" id="title"></h2>
                <p class="expanded-artist" id="artist"></p>
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

    <script src="scripts/footer.js"></script>

    <nav class="mobil-sidebar">
        <div class="nav-home-area">
            <a class="sidebar-home" href="?page=home" data-page="home">
                <span class="home-icon">🏠</span>
                <p>Accueil</p>
            </a>
        </div>

        <div class="nav-playlists-area">
            <a class="sidebar-playlists" href="?page=library" data-page="library">
                <span class="playlists-icon">L</span>
                <p>Library</p>
            </a>
        </div>

        <div class="nav-search-area">
            <a class="sidebar-search" href="?page=search" data-page="search">
                <span class="search-icon">🔍</span>
                <p>Rechercher</p>
            </a>
        </div>

        <div class="nav-add-area">
            <a class="sidebar-add" href="?page=add" data-page="add">
                <span class="add-icon">+</span>
                <p>Ajouter</p>
            </a>
        </div>

        <div class="nav-account-area">
            <a class="sidebar-account" href="?page=account" data-page="account">
                <span class="account-icon">👤</span>
                <p>Compte</p>
            </a>
        </div>
    </nav>
</footer>

<script src="scripts/router.js"></script>

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