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
                echo $req->fetchColumn();
                ?>
            </div>
        </div>
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
    Unison - Version 1.0.3
</article>

