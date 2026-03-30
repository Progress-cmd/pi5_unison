<?php
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
    <link rel="stylesheet" href="styles/accueil.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500;600&display=swap">
    <title>Unison - Accueil</title>
</head>
<body>
    <header>
        <div class="accueil-headline">Bonsoir,<br><em>Francis</em></div>
        <p class="accueil-sub">Que voulez-vous écouter ce soir ?</p>
        <div class="present-moi" style="border: <?php if ($_SESSION['user']['username']=='Francis') { echo "#C8593A"; } else { echo "#4A7C99"; }?> 2px solid;">OO</div>
        <div class="present-toi" style="border: <?php if ($_SESSION['user']['username']=='Cassandre') { echo "#C8593A"; } else { echo "#4A7C99"; }?> 2px solid;">OO</div>
    </header>

    <article class="propositions-bar">
        <button class="proposition">
            <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
            <div class="mini-proposition-info">
                <div class="mini-title">Midnight City</div>
                <div class="mini-artist">M83</div>
            </div>
        </button>
        <button class="proposition">
            <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
            <div class="mini-proposition-info">
                <div class="mini-title">Midnight City</div>
                <div class="mini-artist">M83</div>
            </div>
        </button>
        <button class="proposition">
            <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
            <div class="mini-proposition-info">
                <div class="mini-title">Midnight City</div>
                <div class="mini-artist">M83</div>
            </div>
        </button>
        <button class="proposition">
            <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
            <div class="mini-proposition-info">
                <div class="mini-title">Midnight City</div>
                <div class="mini-artist">M83</div>
            </div>
        </button>
        <button class="proposition">
            <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
            <div class="mini-proposition-info">
                <div class="mini-title">Midnight City</div>
                <div class="mini-artist">M83</div>
            </div>
        </button>
        <button class="proposition">
            <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
            <div class="mini-proposition-info">
                <div class="mini-title">Midnight City</div>
                <div class="mini-artist">M83</div>
            </div>
        </button>
        <button class="proposition">
            <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
            <div class="mini-proposition-info">
                <div class="mini-title">Midnight City</div>
                <div class="mini-artist">M83</div>
            </div>
        </button>
        <button class="proposition">
            <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
            <div class="mini-proposition-info">
                <div class="mini-title">Midnight City</div>
                <div class="mini-artist">M83</div>
            </div>
        </button>
    </article>

    <div class="container">
        <article class="queue-bar">
            <div class="head-bar">Liste d'attente<div class="more-bar">Modifier</div></div>
            <div class="body-bar">
                <div class="content selected">
                    <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
                    <div class="mini-content-info">
                        <div class="mini-title">Midnight City</div>
                        <div class="mini-artist">M83</div>
                    </div>
                    <div class="running">EN COURS</div>
                </div>
                <div class="content">
                    <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
                    <div class="mini-content-info">
                        <div class="mini-title">Midnight City</div>
                        <div class="mini-artist">M83</div>
                    </div>
                    <div class="running">EN COURS</div>
                </div>
                <div class="content">
                    <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
                    <div class="mini-content-info">
                        <div class="mini-title">Midnight City</div>
                        <div class="mini-artist">M83</div>
                    </div>
                    <div class="running">EN COURS</div>
                </div>
                <div class="content">
                    <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
                    <div class="mini-content-info">
                        <div class="mini-title">Midnight City</div>
                        <div class="mini-artist">M83</div>
                    </div>
                    <div class="running">EN COURS</div>
                </div>
            </div>
        </article>

        <article class="playlists-bar">
            <div class="head-bar">Playlists<div class="more-bar">Tout voir</div></div>
            <div class="body-bar">
                <div class="content">
                    <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
                    <div class="mini-content-info">
                        <div class="mini-title">PopRock</div>
                        <div class="mini-info">12 titres - 42 min</div>
                    </div>
                    <button class="material-icons">play_arrow</button>
                </div>
                <div class="content">
                    <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
                    <div class="mini-content-info">
                        <div class="mini-title">PopRock</div>
                        <div class="mini-info">12 titres - 42 min</div>
                    </div>
                    <button class="material-icons">play_arrow</button>
                </div>
                <div class="content">
                    <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
                    <div class="mini-content-info">
                        <div class="mini-title">PopRock</div>
                        <div class="mini-info">12 titres - 42 min</div>
                    </div>
                    <button class="material-icons">play_arrow</button>
                </div>
                <div class="content">
                    <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
                    <div class="mini-content-info">
                        <div class="mini-title">PopRock</div>
                        <div class="mini-info">12 titres - 42 min</div>
                    </div>
                    <button class="material-icons">play_arrow</button>
                </div>
            </div>
        </article>

        <article class="artist-bar">
            <div class="head-bar">Artistes<div class="more-bar">Tout voir</div></div>
            <div class="body-bar">
                <div class="content">
                    <div><img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover"></div>
                    <div class="mini-artist">M83</div>
                </div>
                <div class="content">
                    <div><img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover"></div>
                    <div class="mini-artist">M83</div>
                </div>
                <div class="content">
                    <div><img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover"></div>
                    <div class="mini-artist">M83</div>
                </div>
                <div class="content">
                    <div><img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover"></div>
                    <div class="mini-artist">M83</div>
                </div>
            </div>
        </article>
    </div>

    <aside class="mobil-player">
        <div class="mini-controls">
            <button class="material-icons">
                play_arrow
            </button>
        </div>
        <div class="mini-player-info">
            <div class="mini-title">Midnight City</div>
            <div class="mini-artist">M83</div>
        </div>
        <div class="mini-controls">
            <button class="material-icons"> <!--"material-symbols-outlined"-->
                favorite
            </button>
            <button class="material-icons">
                skip_next
            </button>
        </div>
        <div class="mini-progress-bar">
            <div class="mini-progress-current"></div>
        </div>
    </aside>

    <nav class="mobil-sidebar">
        <div class="nav-home-area">
            <a class="sidebar-home" href="#">
                <span class="home-icon">🏠</span>
                <p>Accueil</p>
            </a>
        </div>

        <div class="nav-playlists-area">
            <a class="sidebar-playlists" href="#">
                <span class="playlists-icon">P</span>
                <p>Playlists</p>
            </a>
        </div>

        <div class="nav-search-area">
            <a class="sidebar-search" href="#">
                <span class="search-icon">🔍</span>
                <p>Rechercher</p>
            </a>
        </div>

        <div class="nav-add-area">
            <a class="sidebar-add" href="#">
                <span class="add-icon">+</span>
                <p>Ajouter</p>
            </a>
        </div>

        <div class="nav-account-area">
            <a class="sidebar-account" href="#">
                <span class="account-icon">👤</span>
                <p>Compte</p>
            </a>
        </div>
    </nav>
</body>
</html>