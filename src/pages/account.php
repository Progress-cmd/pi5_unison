<article class="account-dashboard container">
    <div class="head-bar">Dashboard</div>
    <div class="body-bar">
        <div class="content">
            <div class="dasboard-title">Total Morceaux : </div>
            <div class="dashboard-value">
                <?php
                include_once "../includes/config.php";
                $pdo = Config::getConnection();

                $req = $pdo->prepare("SELECT COUNT(*) FROM tracks");
                $req->execute();
                echo $req->fetchColumn();
                ?>
            </div>
        </div>
        <div class="content">
            <div class="dasboard-title">Total Playlists : </div>
            <div class="dashboard-value">
                <?php
                $req = $pdo->prepare("SELECT COUNT(*) FROM playlists");
                $req->execute();
                echo $req->fetchColumn();
                ?>
            </div>
        </div>
        <div class="content">
            <div class="dasboard-title">Total temps d'écoute : </div>
            <div class="dashboard-value">
                <?php
                session_start();
                $req = $pdo->prepare("SELECT `time-listened` FROM users WHERE id = :user_id");
                $req->execute([':user_id' => $_SESSION['user']['id']]);
                session_write_close();
                echo $req->fetchColumn();
                ?>
            </div>
        </div>
    </div>
</article>

<article class="container account-boutons">
    <div class="header-bar"></div>
    <div class="body-bar">
        <div class="content">
            <a class="more-bar bouton" href="?page=account/infos" data-page="account/infos">
                <span class="add-icon">Infos</span>
            </a>
            <form action="../actions/logout.php" class="bouton">
                <button type="submit">Déconnexion</button>
            </form>
        </div>
    </div>
</article>

