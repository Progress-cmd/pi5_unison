<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/config.php";
include_once "../includes/rendu.php";
include_once "../includes/albums.php";

$pdo = Config::getConnection();
$albums = albumsLister($pdo);

$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<article id="albums-liste" class="containers">
    <div class="head-bar">
        Albums<span class="more-bar"><?= count($albums) ?></span>
    </div>
    <div class="body-bar">
        <?php if (!$albums): ?>
            <?= ligneVide("Aucun album. Collez un lien d'album dans Importation pour commencer.") ?>
        <?php else: ?>
            <div class="albums-grille">
                <?php foreach ($albums as $a): ?>
                    <div class="album-carte" data-album-id="<?= (int) $a['id'] ?>">
                        <img src="<?= $e($a['img'] ?? '') ?>" class="album-img" alt="">
                        <div class="album-titre"><?= $e($a['title']) ?></div>
                        <div class="album-infos">
                            <?= $e($a['artiste'] ?: 'Artiste inconnu') ?>
                            <?php if ($a['annee']): ?> · <?= (int) $a['annee'] ?><?php endif; ?>
                        </div>
                        <div class="album-infos"><?= resumePlaylist((int) $a['nb_titres'], (int) $a['duree']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</article>

<script>
    (function () {
        const liste = document.getElementById('albums-liste');
        if (!liste) return;

        liste.addEventListener('click', (e) => {
            const carte = e.target.closest('.album-carte[data-album-id]');
            if (!carte) return;
            sessionStorage.setItem('album_id', carte.dataset.albumId);
            navigateTo('library/album');
        });
    })();
</script>
