<?php
// Vérification de la connexion utilisateur
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="styles/style.css"> <!-- Feuille de style principale -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"> <!-- Intégration des différents icons -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500;600&display=swap"> <!-- Ajout de deux polices d'écritures -->
    <title>Unison</title>
</head>
<body>
    <!-- La politesse avant tout -->
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
        <div id="headline"><?= $salutation ?>,<br>
            <em><?= $_SESSION['user']['username'] ?></em>
            <p id="headline-sub">Que voulez-vous écouter <?= $moment ?> ?</p>
        </div>
        <section id="persons">
            <div class="first-person user-<?= $_SESSION['user']['id'] ?>">OO</div>
            <div class="second-person user-<?= 3-$_SESSION['user']['id'] ?>">OO</div>
        </section>
    </header>

    <div id="toast-container"></div>

    <!-- Le contenu non statique de la page -->
    <main id="main-content"></main>

    <!-- Le lecteur audio -->
    <footer>
        <section id="player">
            <!-- PLAYER RETRACTED -->
            <div id="retract">
                <div class="player-controls">
                    <button class="buttons material-symbols-outlined play-button">
                        play_arrow
                    </button>
                </div>

                <div class="player-infos">
                    <div class="infos title-info">Loading ...</div>
                    <div class="infos artist-info">Loading ...</div>
                </div>

                <div class="player-controls">
                    <button class="buttons material-symbols-outlined favorite-button">
                        favorite
                    </button>
                    <button class="buttons material-symbols-outlined next-button">
                        skip_next
                    </button>
                </div>

                <div class="player-progress_bar">
                    <div class="player-progress_current"></div>
                </div>
            </div>

            <!-- PLAYER EXTENDED (FULL SCREEN) -->
            <div id="extend">
                <div class="player-controls">
                    <button class="buttons material-symbols-outlined" id="close-button">
                        arrow_forward_ios
                    </button>
                    <button class="buttons material-symbols-outlined" id="more-button">
                        more_vert
                    </button>
                </div>

                <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" alt="" id="player-img">

                <div class="player-controls">
                    <button class="buttons material-symbols-outlined" id="add-button">
                        add
                    </button>
                    <button class="buttons material-symbols-outlined favorite-button">
                        favorite
                    </button>
                </div>

                <div class="player-progress">
                    <div class="player-time">
                        <span class="time-current">0:00</span>
                        <span class="time-total">10:00</span>
                    </div>
                    <div class="player-progress_bar">
                        <div class="player-progress_current"></div>
                    </div>
                </div>

                <div class="player-infos">
                    <div class="infos title-info">Loading ...</div>
                    <div class="infos artist-info">Loading ...</div>
                </div>

                <div class="player-controls">
                    <button class="buttons material-symbols-outlined" id="repeat-button">
                        repeat
                    </button>
                    <button class="buttons material-symbols-outlined prev-button">
                        skip_previous
                    </button>
                    <button class="buttons material-symbols-outlined play-button">
                        play_arrow
                    </button>
                    <button class="buttons material-symbols-outlined next-button">
                        skip_next
                    </button>
                    <button class="buttons material-symbols-outlined" id="rand-button">
                        shuffle
                    </button>
                </div>

                <div class="player-controls">
                    <button class="buttons material-symbols-outlined" id="queue-button">
                        queue_music
                    </button>
                    <button class="buttons material-symbols-outlined" id="volume-button">
                        volume_up <!--volume_down volume_off -->
                    </button>
                    <button class="buttons material-symbols-outlined" id="menu-button">
                        menu <!-- instant_mix -->
                    </button>
                </div>
            </div>
        </section>

        <script src="scripts/player.js"></script>

        <!-- Le menu de navigation -->
        <nav id="navbar">
            <a href="?page=home" data-page="home">
                <div class="icons material-symbols-outlined">home</div>
                Accueil
            </a>

            <a href="?page=library" data-page="library">
                <div class="icons material-symbols-outlined">newsstand</div>
                Bibliothèque
            </a>

            <a href="?page=search" data-page="search">
                <div class="icons material-symbols-outlined">search</div>
                Recherche
            </a>

            <a href="?page=import" data-page="import">
                <div class="icons material-symbols-outlined">add</div>
                Importation
            </a>

            <a href="?page=account" data-page="account">
                <div class="icons material-symbols-outlined">person</div>
                Compte
            </a>
        </nav>
    </footer>

    <script src="scripts/router.js"></script>

</body>
</html>