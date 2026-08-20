<?php
include_once "../includes/auth.php";
exigerConnexion(false);
?>

<article id="queue-bar" class="containers">
    <div class="head-bar">Liste d'attente</div>
    <div class="body-bar" id="queue-full">
        <!-- Rempli par JavaScript -->
    </div>
</article>

<script>
    (function() {
        const queueFull = document.getElementById('queue-full');

        function rendre() {
            window.remplirLignesTitres(queueFull, window.waitPlaylist, {
                file: true,
                badge: true,
                messageVide: "File d'attente vide",
            });

            // Amène le morceau en cours sous les yeux, sans animer : la page
            // vient de s'ouvrir, il n'y a rien à suivre du regard.
            const courant = queueFull.querySelector('.selected');
            if (courant) courant.scrollIntoView({ block: 'center' });
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
