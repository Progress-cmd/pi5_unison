<article class="propositions-bar">
    <?php
    include_once "../includes/config.php";
    $pdo = Config::getConnection();

    $req = $pdo->prepare("SELECT id FROM tracks ORDER BY RAND() LIMIT 8");
    $req->execute();

    $listTracks = $req->fetchAll(PDO::FETCH_COLUMN);

    foreach ($listTracks as $listTrack)
    {
        $req = $pdo->prepare("SELECT img, title, GROUP_CONCAT(artists.name SEPARATOR ', ') AS artists_names
                                    FROM tracks
                                    LEFT JOIN artist__track ON artist__track.track_id = tracks.id
                                    LEFT JOIN artists ON artists.id = artist__track.artist_id
                                    WHERE tracks.id = :track
                                    ");
        $req->bindParam(":track", $listTrack);
        $req->execute();

        $track = $req->fetchAll();

        echo '<button class="proposition" onclick="loadTrack('.$listTrack.')">
                  <img src="'.$track[0]["img"].'" class="mini-player-img" alt="image">
                  <div class="mini-proposition-info">
                      <div class="mini-title">'.$track[0]["title"].'</div>
                      <div class="mini-artist">'.$track[0]["artists_names"].'</div>
                  </div>
              </button>';
    }
    ?>
</article>

<div class="box">
    <article class="queue-bar container">
        <div class="head-bar">Liste d'attente<div class="more-bar">Modifier</div></div>
        <div class="body-bar">
            <?php
            session_start();

            $req = $pdo->prepare("SELECT tracks.id, tracks.img, tracks.title, GROUP_CONCAT(artists.name SEPARATOR ', ') AS artists_names
                                        FROM playlists
                                        LEFT JOIN track__playlist ON playlist_id = playlists.id
                                        LEFT JOIN tracks ON track_id = tracks.id
                                        LEFT JOIN artist__track ON artist__track.track_id = tracks.id
                                        LEFT JOIN artists ON artists.id = artist__track.artist_id
                                        WHERE playlists.name = 'Wait Tracks' AND playlists.`created-by_id` = :user_id
                                        GROUP BY tracks.id, tracks.img, tracks.title, track__playlist.position
                                        ORDER BY track__playlist.position
                                        ");
            $req->execute([':user_id' => $_SESSION['user']['id']]);
            session_write_close();
            $titres = $req->fetchAll();

            $select = "selected";

            foreach ($titres as $titre) {
                echo '
                <div class="content '.$select.'" onclick="loadTrack('.$titre["id"].')">
                    <img src="'.$titre["img"].'" class="mini-player-img" alt="image">
                    <div class="mini-content-info">
                        <div class="mini-title">'.$titre["title"].'</div>
                        <div class="mini-artist">'.$titre["artists_names"].'</div>
                    </div>
                    <div class="running">EN COURS</div>
                </div>';
                $select = "";
            }
            ?>
        </div>
    </article>

    <article class="playlists-bar container">
        <div class="head-bar">Playlists<div class="more-bar">Tout voir</div></div>
        <div class="body-bar">
            <?php
            $req = $pdo->prepare("SELECT playlists.id, name, username FROM playlists LEFT JOIN users ON playlists.`created-by_id` = users.id WHERE name != 'Wait Tracks'");
            $req->execute();

            $playlists = $req->fetchAll();

            foreach ($playlists as $playlist)
            {
                $req = $pdo->prepare("SELECT COUNT(*) FROM track__playlist WHERE playlist_id = :playlist");
                $req->bindParam(":playlist", $playlist["id"]);
                $req->execute();

                $occurrence = $req->fetchColumn();
                ?>
                <div class="content">
                    <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover">
                    <div class="mini-content-info">
                        <div class="mini-title"><?php echo $playlist["name"]; ?></div>
                        <div style="font-size: 10px"><?php echo $playlist["username"]; ?></div>
                        <div class="mini-info"><?php if ($occurrence > 1) { echo $occurrence.' titres'; } else { echo $occurrence.' titre'; } ?> - 42 min</div>
                    </div>
                    <button class="material-icons">play_arrow</button>
                </div>
                <?php
            }
            ?>
        </div>
    </article>

    <article class="artist-bar container">
        <?php
        $req = $pdo->prepare("
            SELECT artists.name, COUNT(tracks.id) AS track_count
            FROM artists
            LEFT JOIN artist__track ON artist__track.artist_id = artists.id
            LEFT JOIN tracks ON tracks.id = artist__track.track_id
            GROUP BY artists.id, artists.name
            ORDER BY track_count DESC
            LIMIT 4
        ");
        $req->execute();

        $listArtists = $req->fetchAll();
        ?>
        <div class="head-bar">Artistes<a href="?page=home/artists" class="more-bar"  data-page="home/artists">Tout voir</a></div>
        <div class="body-bar">
            <?php
                foreach ($listArtists as $artist) {
                    echo '<div class="content">
                              <div><img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" class="mini-player-img" alt="Cover"></div>
                              <div class="mini-artist">'.$artist["name"].'</div>
                          </div>';
                }
            ?>
        </div>
    </article>
</div>