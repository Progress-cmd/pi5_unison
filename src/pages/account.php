<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/rendu.php";
?>
<article class="containers" id="account-dashboard">
    <div class="head-bar">Dashboard</div>
    <div class="body-bar">
        <div class="content">
            <div class="dasboard-title"><b>Total Morceaux : </b></div>
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
            <div class="dasboard-title"><b>Total Playlists : </b></div>
            <div class="dashboard-value">
                <?php
                /*
                 * Les playlists système sont écartées par leur nom, et non
                 * plus en retranchant 2 du total : ce « -2 » supposait deux
                 * comptes et deux seulement. À trois comptes il comptait une
                 * file d'attente en trop ; à un seul il affichait -1.
                 */
                $req = $pdo->prepare("
                    SELECT COUNT(*) FROM playlists
                    WHERE name NOT IN ('Wait Tracks', 'Favorite Tracks')
                ");
                $req->execute();
                echo $req->fetchColumn();
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

        if (!$topTitres) { echo ligneVide('Aucune écoute pour le moment'); }

        foreach ($topTitres as $topTitre) {
            $nbLibelle = $topTitre['nb'] > 1 ? $topTitre['nb'].' écoutes' : $topTitre['nb'].' écoute';
            echo ligneTitre($topTitre, [
                'sous_titre' => ($topTitre['artists_names'] ?? '') . ' - ' . $nbLibelle,
            ]);
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

        if (!$ecoutes) { echo ligneVide('Aucune écoute pour le moment'); }

        foreach ($ecoutes as $ecoute) {
            echo ligneTitre($ecoute, [
                'sous_titre' => ($ecoute['artists_names'] ?? '')
                              . ' - ' . date('d/m/Y H:i', strtotime($ecoute['listened-at'])),
            ]);
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
        </div>
    </div>
</article>

<article id="account-version">
    <?= versionUnison() ?>
</article>

