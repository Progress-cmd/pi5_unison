<article id="account-infos" class="containers">
    <div class="head-bar">Informations</div>
    <div class="body-bar">
        <?php
        session_start();

        include_once "../includes/config.php";
        $pdo = Config::getConnection();

        $req = $pdo->prepare("SELECT username, email FROM users WHERE id = :user_id");
        $req->execute([':user_id' => $_SESSION['user']['id']]);
        $data = $req->fetchAll();
        ?>
        <div class="content">
            <b>Nom d'utilisateur :</b> <?= $data[0]["username"]; ?>
        </div>
        <div class="content">
            <b>Email : </b> <?= $data[0]["email"]; ?>
        </div>
    </div>
</article>