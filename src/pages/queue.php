<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/config.php";

/*
 * Identifiant de la file, résolu ici comme le fait l'accueil.
 *
 * Le glisser-déposer a besoin de savoir dans quelle playlist il réordonne.
 * Le prendre dans window.waitPlaylist ne marcherait pas : selon le chemin par
 * lequel la file a ete remplie (clear_queue_and_add, file_ajouter), les
 * lignes ne portent pas toutes playlist_id. La base, elle, le sait toujours.
 */
$pdo = Config::getConnection();
$req = $pdo->prepare("SELECT id FROM playlists WHERE name = 'Wait Tracks' AND `created-by_id` = :u");
$req->execute([':u' => (int) ($_SESSION['user']['id'] ?? 0)]);
$fileId = (int) $req->fetchColumn();
?>

<article id="queue-bar" class="containers">
    <div class="head-bar">Liste d'attente</div>
    <div class="body-bar" id="queue-full" data-playlist-id="<?= $fileId ?>">
        <!-- Rempli par JavaScript -->
    </div>
</article>

<script src="<?= assetVersionne('../scripts/dragdrop.js') ?>"></script>
<script>
    (function() {
        const queueFull = document.getElementById('queue-full');

        const fileId = <?= json_encode($fileId) ?>;

        /*
         * À rejouer après chaque rendu : remplirLignesTitres() remplace les
         * lignes, et les écouteurs posés sur les anciennes disparaissent avec
         * elles. C'est ce qui manquait ici — la page affichait la file mais on
         * ne pouvait rien y réordonner, alors que l'accueil le permettait.
         */
        function brancherReordonnancement() {
            if (!fileId || typeof window.enableDragDrop !== 'function') return;
            window.enableDragDrop(queueFull, fileId);
        }

        function rendre() {
            window.remplirLignesTitres(queueFull, window.waitPlaylist, {
                file: true,
                badge: true,
                messageVide: "File d'attente vide",
            });

            brancherReordonnancement();

            // Amène le morceau en cours sous les yeux, sans animer : la page
            // vient de s'ouvrir, il n'y a rien à suivre du regard.
            const courant = queueFull.querySelector('.selected');
            if (courant) courant.scrollIntoView({ block: 'center' });
        }

        /*
         * dragdrop.js n'est pas chargé par l'ossature : si le script arrive
         * après ce rendu, on rebranche à son chargement.
         */
        if (typeof window.enableDragDrop !== 'function') {
            let essais = 0;
            const attendre = setInterval(() => {
                if (typeof window.enableDragDrop === 'function' || ++essais > 40) {
                    clearInterval(attendre);
                    brancherReordonnancement();
                }
            }, 100);
        }

        rendre();

        /*
         * Si l'application a été ouverte directement sur cette page, le player
         * récupère la file d'attente en arrière-plan : on se réaffiche quand
         * elle arrive, au lieu de rester sur « File d'attente vide ».
         */
        window.addEventListener('queueReady', rendre, { once: true });
    })();
</script>

<style>
    #queue-bar {
        display: flex;
        flex-direction: column;
        height: 100%;
        margin: 0;
        padding: 18px;
    }
    
    #queue-bar .head-bar {
        margin-bottom: 10px;
        flex-shrink: 0;
    }
    
    #queue-bar .body-bar {
        flex-grow: 1;
        overflow-y: auto;
    }
    
    #queue-bar .mini-song {
        margin: 0 !important;
    }
</style>
