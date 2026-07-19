<article class="containers" id="account-dashboard">
    <div class="head-bar">Dashboard</div>
    <div class="body-bar">
        <div class="content">
            <div class="dasboard-title"><b>Total Morceaux : </b></div>
            <div class="dashboard-value">
                <?php
                session_start();

                include_once "../includes/config.php";
                $pdo = Config::getConnection();

                $req = $pdo->prepare("SELECT COUNT(*) FROM tracks");
                $req->execute();
                echo $req->fetchColumn();
                ?>
            </div>
        </div>
        <div class="content">
            <div class="dasboard-title"><b>Total Playlists : </b></div>
            <div class="dashboard-value">
                <?php
                $req = $pdo->prepare("SELECT COUNT(*) FROM playlists");
                $req->execute();
                echo $req->fetchColumn()-2; // On retire les deux playlists Wait track
                ?>
            </div>
        </div>
        <div class="content">
            <div class="dasboard-title"><b>Total temps d'écoute : </b></div>
            <div class="dashboard-value">
                <?php
                $req = $pdo->prepare("SELECT `time-listened` FROM users WHERE id = :user_id");
                $req->execute([':user_id' => $_SESSION['user']['id']]);
                $tempsEcoute = intval($req->fetchColumn());
                if ($tempsEcoute >= 3600) {
                    echo intdiv($tempsEcoute, 3600).'h'.str_pad(intdiv($tempsEcoute % 3600, 60), 2, '0', STR_PAD_LEFT);
                } else {
                    echo intdiv($tempsEcoute, 60).' min';
                }
                ?>
            </div>
        </div>
    </div>
</article>

<article class="containers" id="top-tracks">
    <div class="head-bar">Top titres</div>
    <div class="body-bar">
        <?php
        $req = $pdo->prepare("
                SELECT tracks.id, tracks.title, tracks.img, nb_listen.nb,
                       GROUP_CONCAT(DISTINCT artists.name SEPARATOR ', ') AS artists_names
                FROM nb_listen
                JOIN tracks ON tracks.id = nb_listen.track_id
                LEFT JOIN artist__track ON artist__track.track_id = tracks.id
                LEFT JOIN artists ON artists.id = artist__track.artist_id
                WHERE nb_listen.user_id = :user_id
                GROUP BY tracks.id, tracks.title, tracks.img, nb_listen.nb
                ORDER BY nb_listen.nb DESC
                LIMIT 3
            ");
        $req->execute([':user_id' => $_SESSION['user']['id']]);
        $topTitres = $req->fetchAll(PDO::FETCH_ASSOC);

        if (!$topTitres) {
            echo '<div class="content">Aucune écoute pour le moment</div>';
        }

        foreach ($topTitres as $topTitre) {
            $nbLibelle = $topTitre['nb'] > 1 ? $topTitre['nb'].' écoutes' : $topTitre['nb'].' écoute';
            echo '<div class="content mini-song" data-track-id="'.$topTitre['id'].'" onclick="loadTrack('.$topTitre['id'].')">
                      <img src="'.htmlspecialchars($topTitre['img']).'" class="song-img" alt=" ">
                      <div class="song-infos">
                          <div class="song-title">'.htmlspecialchars($topTitre['title']).'</div>
                          <div class="song-artist">'.htmlspecialchars($topTitre['artists_names']).' - '.$nbLibelle.'</div>
                      </div>
                      <button class="buttons material-symbols-outlined">more_vert</button>
                  </div>';
        }
        ?>
    </div>
</article>

<article class="containers" id="recent-listens">
    <div class="head-bar">Écoutes récentes</div>
    <div class="body-bar">
        <?php
        $req = $pdo->prepare("
                SELECT historical.`listened-at`, tracks.id, tracks.title, tracks.img,
                       GROUP_CONCAT(DISTINCT artists.name SEPARATOR ', ') AS artists_names
                FROM historical
                JOIN tracks ON tracks.id = historical.track_id
                LEFT JOIN artist__track ON artist__track.track_id = tracks.id
                LEFT JOIN artists ON artists.id = artist__track.artist_id
                WHERE historical.`listened-by_id` = :user_id
                GROUP BY historical.`listened-at`, tracks.id, tracks.title, tracks.img
                ORDER BY historical.`listened-at` DESC
                LIMIT 5
            ");
        $req->execute([':user_id' => $_SESSION['user']['id']]);
        $ecoutes = $req->fetchAll(PDO::FETCH_ASSOC);

        if (!$ecoutes) {
            echo '<div class="content">Aucune écoute pour le moment</div>';
        }

        foreach ($ecoutes as $ecoute) {
            echo '<div class="content mini-song" data-track-id="'.$ecoute['id'].'" onclick="loadTrack('.$ecoute['id'].')">
                      <img src="'.htmlspecialchars($ecoute['img']).'" class="song-img" alt=" ">
                      <div class="song-infos">
                          <div class="song-title">'.htmlspecialchars($ecoute['title']).'</div>
                          <div class="song-artist">'.htmlspecialchars($ecoute['artists_names']).' - '.date('d/m/Y H:i', strtotime($ecoute['listened-at'])).'</div>
                      </div>
                      <button class="buttons material-symbols-outlined">more_vert</button>
                  </div>';
        }
        ?>
    </div>
</article>

<article class="containers" id="account-boutons">
    <div class="body-bar">
        <div class="content">
            <a class="redirect buttons" href="?page=account/infos" data-page="account/infos">
                <span>Infos</span>
            </a>
            <form action="../actions/logout.php">
                <button type="submit" class="buttons">Déconnexion</button>
            </form>
        </div>
    </div>
</article>

<article id="account-version">
    Unison - Version 1.0.4
</article>

