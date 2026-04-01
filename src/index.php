<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

include "header.php";
?>
<main>
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
</main>

<?php
include "footer.php";
?>