<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/config.php";
include_once "../includes/rendu.php";
include_once "../includes/albums.php";

$pdo = Config::getConnection();
$albumId = (int) filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$album = $albumId ? albumDetail($pdo, $albumId) : null;

$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if (!$album) {
    echo '<article class="containers"><div class="body-bar">'
       . ligneVide('Album introuvable') . '</div></article>';
    return;
}

$titres = albumTitres($pdo, $albumId);

/*
 * Combien de titres ce sont l'album qui les a fait entrer ? C'est le seul
 * chiffre qui compte au moment de supprimer : les autres seront conservés,
 * et l'utilisateur doit le savoir avant de confirmer, pas après.
 */
$venusDeLAlbum = 0;
foreach ($titres as $t) {
    if ((int) ($t['album_source_id'] ?? 0) === $albumId) { $venusDeLAlbum++; }
}
$dureeTotale = array_sum(array_column($titres, 'duration'));
?>
<article id="album-detail" class="containers" data-album-id="<?= $albumId ?>">
    <div class="head-bar">
        <a href="?page=library/albums" data-page="library/albums" class="redirect">← Albums</a>
    </div>

    <div class="album-entete">
        <img src="<?= $e($album['img'] ?? '') ?>" class="album-img-grande" alt="">
        <div class="album-entete-infos">
            <div class="album-entete-titre"><?= $e($album['title']) ?></div>
            <div class="album-infos">
                <?= $e($album['artiste'] ?: 'Artiste inconnu') ?>
                <?php if ($album['annee']): ?> · <?= (int) $album['annee'] ?><?php endif; ?>
            </div>
            <div class="album-infos"><?= resumePlaylist(count($titres), (int) $dureeTotale) ?></div>

            <div class="album-actions">
                <button type="button" class="buttons album-lire" id="album-lire">
                    <span class="material-symbols-outlined">play_arrow</span> Écouter
                </button>
                <button type="button" class="buttons album-supprimer" id="album-supprimer"
                        data-venus="<?= $venusDeLAlbum ?>"
                        data-conserves="<?= count($titres) - $venusDeLAlbum ?>">
                    Supprimer l'album
                </button>
            </div>
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Titres</div>
    <div class="body-bar">
        <?php
        foreach ($titres as $i => $t) {
            echo ligneTitre($t, [
                'sous_titre' => $t['artists_names'] ?: ($album['artiste'] ?: 'Artiste inconnu'),
                'index'      => null,
            ]);
        }
        if (!$titres) { echo ligneVide('Aucun titre dans cet album'); }
        ?>
    </div>
</article>

<script>
    (function () {
        const bloc = document.getElementById('album-detail');
        if (!bloc) return;
        const albumId = bloc.dataset.albumId;

        // --- Écouter l'album : remplace la file d'attente
        document.getElementById('album-lire').addEventListener('click', async () => {
            try {
                const res = await fetch('actions/album_lire.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'album_id=' + encodeURIComponent(albumId),
                });
                const data = await res.json();

                if (!data.success || !data.tracks || data.tracks.length === 0) {
                    window.showToast(data.message || 'Album vide', 'error');
                    return;
                }

                window.waitPlaylist = data.tracks;
                window.sourcePlaylistId = null;
                window.currentIndex = 0;
                loadTrack(data.tracks[0].id, true);
                window.showToast('Lecture de l\'album', 'success');
            } catch (e) {
                window.showToast('Erreur réseau', 'error');
            }
        });

        // --- Supprimer : l'utilisateur doit savoir ce qui part et ce qui reste
        document.getElementById('album-supprimer').addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const venus = parseInt(btn.dataset.venus, 10);
            const conserves = parseInt(btn.dataset.conserves, 10);

            let message = "Supprimer cet album ?\n\n";
            message += venus > 0
                ? venus + (venus > 1 ? ' titres seront supprimés' : ' titre sera supprimé')
                    + " (base et fichier) : ce sont ceux que cet album a fait entrer.\n"
                : "Aucun titre ne sera supprimé.\n";
            if (conserves > 0) {
                message += conserves + (conserves > 1 ? ' titres seront conservés' : ' titre sera conservé')
                    + ", simplement détaché" + (conserves > 1 ? 's' : '') + " de l'album.\n";
            }
            if (!confirm(message)) return;

            btn.disabled = true;
            try {
                const res = await fetch('actions/album_supprimer.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'album_id=' + encodeURIComponent(albumId),
                });
                const data = await res.json();

                if (data.success) {
                    window.showToast(data.message, 'success');
                    navigateTo('library/albums');
                } else {
                    window.showToast(data.message || 'Suppression impossible', 'error');
                    btn.disabled = false;
                }
            } catch (e) {
                window.showToast('Erreur réseau', 'error');
                btn.disabled = false;
            }
        });
    })();
</script>
