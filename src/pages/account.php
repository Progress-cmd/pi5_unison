<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/rendu.php";
include_once "../includes/config.php";
include_once "../includes/statistiquesCompte.php";

$moi = (int) $_SESSION['user']['id'];
$pdoStats = Config::getConnection();
$collections = statCollections($pdoStats, $moi);
$topArtistes = statTopArtistes($pdoStats, $moi);
$semaine     = statSemaine($pdoStats, $moi);
$moments     = statMoments($pdoStats, $moi);
$decouvertes = statDecouvertes($pdoStats);
$maxSemaine  = max(1, max(array_column($semaine, 'n')));
$totalMoments = array_sum($moments);
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
            <div class="dasboard-title"><b>Albums : </b></div>
            <div class="dashboard-value"><?= (int) $collections['albums'] ?></div>
        </div>
        <div class="content">
            <div class="dasboard-title"><b>Artistes favoris : </b></div>
            <div class="dashboard-value"><?= (int) $collections['artistes_favoris'] ?></div>
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

<article class="containers" id="top-artistes">
    <div class="head-bar">Top artistes</div>
    <div class="body-bar">
        <?php if (!$topArtistes): ?>
            <?= ligneVide('Aucune écoute pour le moment') ?>
        <?php else: foreach ($topArtistes as $a): ?>
            <div class="content mini-song" data-artiste-id="<?= (int) $a['id'] ?>">
                <img src="<?= htmlspecialchars($a['img'] ?? '', ENT_QUOTES) ?>" class="song-img" alt="">
                <div class="song-infos">
                    <div class="song-title"><?= htmlspecialchars($a['name'], ENT_QUOTES) ?></div>
                    <div class="song-artist">
                        <?= (int) $a['ecoutes'] ?><?= $a['ecoutes'] > 1 ? ' écoutes' : ' écoute' ?>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</article>

<article class="containers" id="semaine-ecoute">
    <div class="head-bar">Votre semaine</div>
    <div class="body-bar">
        <?php if ($maxSemaine <= 0 || array_sum(array_column($semaine, 'n')) === 0): ?>
            <?= ligneVide('Aucune écoute ces sept derniers jours') ?>
        <?php else: ?>
            <div class="semaine-graphe">
                <?php foreach ($semaine as $j): ?>
                    <div class="semaine-jour" title="<?= (int) $j['n'] ?> écoute(s) le <?= htmlspecialchars($j['libelle'], ENT_QUOTES) ?>">
                        <div class="semaine-barre-fond">
                            <?php /* Hauteur relative au jour le plus chargé : une barre
                                      pleine veut dire « le maximum de la semaine », pas
                                      un nombre absolu. */ ?>
                            <div class="semaine-barre" style="height: <?= (int) round($j['n'] / $maxSemaine * 100) ?>%"></div>
                        </div>
                        <div class="semaine-libelle"><?= htmlspecialchars(mb_substr($j['libelle'], 0, 1), ENT_QUOTES) ?></div>
                        <div class="semaine-nombre"><?= (int) $j['n'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</article>

<article class="containers" id="moments-ecoute">
    <div class="head-bar">Vos moments d'écoute</div>
    <div class="body-bar">
        <?php if ($totalMoments === 0): ?>
            <?= ligneVide('Aucune écoute pour le moment') ?>
        <?php else:
            $libelles = ['matin' => 'Matin', 'apres_midi' => 'Après-midi', 'soir' => 'Soir', 'nuit' => 'Nuit'];
            foreach ($moments as $cle => $n): ?>
            <div class="moment-ligne">
                <div class="moment-nom"><?= $libelles[$cle] ?></div>
                <div class="moment-jauge">
                    <div class="moment-remplie" style="width: <?= (int) round($n / $totalMoments * 100) ?>%"></div>
                </div>
                <div class="moment-nombre"><?= (int) $n ?></div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</article>

<article class="containers" id="decouvertes">
    <div class="head-bar">Ajouts récents</div>
    <div class="body-bar">
        <?php if (!$decouvertes): ?>
            <?= ligneVide('Aucun titre ajouté ce mois-ci') ?>
        <?php else: foreach ($decouvertes as $t): ?>
            <?= ligneTitre($t, [
                'sous_titre' => ($t['artists_names'] ?: 'Artiste inconnu')
                              . ' - ' . date('d/m', strtotime($t['created-at'])),
            ]) ?>
        <?php endforeach; endif; ?>
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
            <a class="redirect buttons" href="?page=account/parametres" data-page="account/parametres">
                <span>Paramètres</span>
            </a>
        </div>
    </div>
</article>

<script>
    (function () {
        const top = document.getElementById('top-artistes');
        if (!top) return;
        top.addEventListener('click', (e) => {
            const ligne = e.target.closest('[data-artiste-id]');
            if (!ligne) return;
            sessionStorage.setItem('artiste_id', ligne.dataset.artisteId);
            navigateTo('library/artiste');
        });
    })();
</script>

<article id="account-version">
    <?= versionUnison() ?>
</article>

