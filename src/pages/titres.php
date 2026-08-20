<?php
include_once "../includes/auth.php";
exigerConnexion(false);
?>
<article id="titres-liste" class="containers">
    <div class="head-bar">Tous les titres<span id="titres-compteur" class="more-bar"></span></div>
    <div class="body-bar" id="titres-corps"></div>

    <!-- Sentinelle : sa venue à l'écran déclenche le paquet suivant. -->
    <div id="titres-sentinelle"></div>
    <div id="titres-etat" class="titres-etat">Chargement…</div>
</article>

<script>
    (function() {
        const corps = document.getElementById('titres-corps');
        const etat = document.getElementById('titres-etat');
        const compteur = document.getElementById('titres-compteur');
        const sentinelle = document.getElementById('titres-sentinelle');
        if (!corps) return;

        const PAQUET = 30;
        let offset = 0;
        let total = null;
        let enCours = false;
        let termine = false;

        function ajouter(titres) {
            const fragment = document.createDocumentFragment();
            titres.forEach(titre => fragment.appendChild(window.creerLigneTitre(titre)));
            corps.appendChild(fragment);

            window.corrigerImagesVides(corps);
            if (window.initializeTrackContextMenus) window.initializeTrackContextMenus();
        }

        async function chargerSuite() {
            // Une seule requête à la fois : le défilement peut faire entrer la
            // sentinelle plusieurs fois avant l'arrivée de la réponse.
            if (enCours || termine) return;
            enCours = true;
            etat.textContent = 'Chargement…';

            try {
                const res = await fetch(`actions/lister_titres.php?offset=${offset}&limite=${PAQUET}`);
                const data = await res.json();

                if (!data.success) throw new Error(data.message || 'Erreur');

                total = data.total;
                ajouter(data.tracks);
                offset += data.tracks.length;

                compteur.textContent = `${offset} / ${total}`;

                if (data.tracks.length < PAQUET || offset >= total) {
                    termine = true;
                    observateur.disconnect();
                    etat.textContent = total === 0 ? 'Aucun titre' : 'Fin de la liste';
                } else {
                    etat.textContent = '';
                }
            } catch (e) {
                etat.textContent = 'Erreur de chargement — faites défiler pour réessayer';
            } finally {
                enCours = false;
            }
        }

        /*
         * IntersectionObserver plutôt qu'un écouteur de défilement : la page
         * est injectée par le routeur et ne sait pas quel conteneur défile.
         * Une marge basse déclenche le paquet suivant avant d'arriver au vide.
         */
        const observateur = new IntersectionObserver((entrees) => {
            if (entrees.some(e => e.isIntersecting)) chargerSuite();
        }, { rootMargin: '400px' });

        observateur.observe(sentinelle);

        chargerSuite();
    })();
</script>
