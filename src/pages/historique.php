<?php
/**
 * Historique d'écoute complet du compte.
 *
 * Chargé par paquets, comme « Tous les titres » : l'historique n'a pas de
 * plafond — il grandit d'une ligne à chaque titre écouté — et tout injecter
 * d'un coup finirait par figer la page.
 */
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/rendu.php";
?>
<article id="historique-liste" class="containers">
    <div class="head-bar">Historique d'écoute<span id="historique-compteur" class="more-bar"></span></div>
    <div class="body-bar" id="historique-corps"><?= squelettes(5) ?></div>

    <!-- Sentinelle : sa venue à l'écran déclenche le paquet suivant. -->
    <div id="historique-sentinelle"></div>
    <div id="historique-etat" class="titres-etat"></div>
</article>

<script>
    (function () {
        const corps = document.getElementById('historique-corps');
        const etat = document.getElementById('historique-etat');
        const compteur = document.getElementById('historique-compteur');
        const sentinelle = document.getElementById('historique-sentinelle');
        if (!corps) return;

        const PAQUET = 30;
        let offset = 0;
        let total = null;
        let enCours = false;
        let termine = false;

        /*
         * Dernier jour affiché, conservé entre les paquets : un même jour peut
         * chevaucher deux chargements, et on ne veut pas de deuxième en-tête
         * au milieu de ses écoutes.
         */
        let jourCourant = null;

        const AUJOURDHUI = new Date().toDateString();
        const HIER = new Date(Date.now() - 86400000).toDateString();

        /** « Aujourd'hui », « Hier », sinon « lundi 22 septembre ». */
        function nomDuJour(date) {
            const j = date.toDateString();
            if (j === AUJOURDHUI) return "Aujourd'hui";
            if (j === HIER) return 'Hier';

            const options = { weekday: 'long', day: 'numeric', month: 'long' };
            // L'année n'apparaît que si elle diffère de l'année en cours :
            // la répéter sur chaque séparateur n'apprend rien.
            if (date.getFullYear() !== new Date().getFullYear()) options.year = 'numeric';

            return date.toLocaleDateString('fr-FR', options);
        }

        function heure(date) {
            return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        }

        function ajouter(ecoutes) {
            const fragment = document.createDocumentFragment();

            ecoutes.forEach(e => {
                /*
                 * Instant absolu (epoch) : le serveur ne nous envoie pas une
                 * heure déjà interprétée. C'est le navigateur qui la rend dans
                 * le fuseau de qui regarde — la base, elle, tourne en UTC.
                 */
                const date = new Date(Number(e.ecoute_ts) * 1000);
                const jour = date.toDateString();

                if (jour !== jourCourant) {
                    jourCourant = jour;
                    const sep = document.createElement('div');
                    sep.className = 'historique-jour';
                    sep.textContent = nomDuJour(date);
                    fragment.appendChild(sep);
                }

                const artistes = e.artists_names || '';
                fragment.appendChild(window.creerLigneTitre(e, {
                    sousTitre: artistes ? artistes + ' · ' + heure(date) : heure(date),
                }));
            });

            corps.appendChild(fragment);

            window.corrigerImagesVides(corps);
            if (window.initializeTrackContextMenus) window.initializeTrackContextMenus();
        }

        async function chargerSuite() {
            // Une seule requête à la fois : le défilement peut faire entrer la
            // sentinelle plusieurs fois avant l'arrivée de la réponse.
            if (enCours || termine) return;
            enCours = true;
            // Les squelettes disent déjà qu'on charge : le mot ne sert que
            // pour les paquets suivants, quand ils ont disparu.
            etat.textContent = corps.querySelector('.squelette-ligne') ? '' : 'Chargement…';

            try {
                const res = await fetch(`actions/lister_historique.php?offset=${offset}&limite=${PAQUET}`);
                const data = await res.json();

                if (!data.success) throw new Error(data.message || 'Erreur');

                total = data.total;
                corps.querySelectorAll('.squelette-ligne').forEach(e => e.remove());
                ajouter(data.ecoutes);
                offset += data.ecoutes.length;

                compteur.textContent = `${offset} / ${total}`;

                if (data.ecoutes.length < PAQUET || offset >= total) {
                    termine = true;
                    observateur.disconnect();
                    etat.textContent = total === 0
                        ? "Aucune écoute pour le moment"
                        : "Début de l'historique";
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
