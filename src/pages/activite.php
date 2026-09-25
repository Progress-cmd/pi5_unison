<?php
/**
 * Fil d'activité : ce qui a changé dans l'application, et par qui.
 *
 * Chargé par paquets, comme l'historique d'écoute : le fil grandit à chaque
 * import, chaque note, chaque message, et n'a pas de plafond.
 */
include_once "../includes/auth.php";
exigerConnexion(false);
?>
<article id="activite-liste" class="containers">
    <div class="head-bar">Activité<span id="activite-compteur" class="more-bar"></span></div>
    <div class="body-bar" id="activite-corps"></div>

    <!-- Sentinelle : sa venue à l'écran déclenche le paquet suivant. -->
    <div id="activite-sentinelle"></div>
    <div id="activite-etat" class="titres-etat">Chargement…</div>
</article>

<script>
    (function () {
        const corps = document.getElementById('activite-corps');
        const etat = document.getElementById('activite-etat');
        const compteur = document.getElementById('activite-compteur');
        const sentinelle = document.getElementById('activite-sentinelle');
        if (!corps) return;

        const PAQUET = 30;
        let offset = 0;
        let enCours = false;
        let termine = false;
        let jourCourant = null;

        /*
         * Un type d'événement : son icône, et la phrase qui l'introduit. Tout
         * est déclaré ici — ajouter un type au fil ne demande rien d'autre que
         * d'ajouter sa ligne, côté serveur comme côté affichage.
         */
        const TYPES = {
            titre:          { icone: 'music_note',    verbe: 'a ajouté le titre' },
            album:          { icone: 'album',         verbe: "a importé l'album" },
            playlist:       { icone: 'queue_music',   verbe: 'a créé la playlist' },
            note:           { icone: 'sticky_note_2', verbe: 'a écrit une note sur' },
            artiste_favori: { icone: 'favorite',      verbe: 'a mis en favori' },
            message:        { icone: 'forum',         verbe: 'a écrit' },
        };

        const AUJOURDHUI = new Date().toDateString();
        const HIER = new Date(Date.now() - 86400000).toDateString();

        function nomDuJour(date) {
            const j = date.toDateString();
            if (j === AUJOURDHUI) return "Aujourd'hui";
            if (j === HIER) return 'Hier';
            const o = { weekday: 'long', day: 'numeric', month: 'long' };
            if (date.getFullYear() !== new Date().getFullYear()) o.year = 'numeric';
            return date.toLocaleDateString('fr-FR', o);
        }

        const heure = d => d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

        /*
         * Construit par le DOM et non par innerHTML : les libellés sont des
         * titres de musique, des noms de playlist et des messages — du texte
         * qu'on ne contrôle pas.
         */
        function ligne(ev) {
            const type = TYPES[ev.type] || { icone: 'bolt', verbe: 'a fait' };

            const bloc = document.createElement('div');
            bloc.className = 'activite-ligne';

            const icone = document.createElement('span');
            icone.className = 'material-symbols-outlined activite-icone';
            icone.textContent = type.icone;

            const texte = document.createElement('div');
            texte.className = 'activite-texte';

            const phrase = document.createElement('div');
            const acteur = document.createElement('b');
            acteur.textContent = ev.acteur;
            phrase.append(acteur, document.createTextNode(' ' + type.verbe + ' '));

            /*
             * Un message se cite entre guillemets ; le reste est un nom propre
             * de l'application, qu'on met en valeur. Sans libellé — une note
             * dont la cible a disparu —, la phrase se termine sans compléter.
             */
            if (ev.libelle) {
                const cible = document.createElement(ev.type === 'message' ? 'i' : 'span');
                cible.className = 'activite-cible';
                cible.textContent = ev.type === 'message' ? '« ' + ev.libelle + ' »' : ev.libelle;
                phrase.appendChild(cible);
            }

            const quand = document.createElement('div');
            quand.className = 'activite-heure';
            quand.textContent = heure(new Date(ev.ts * 1000));

            texte.append(phrase, quand);
            bloc.append(icone, texte);

            // Un titre mène à sa fiche ; les autres types n'ont pas tous de
            // page dédiée, on ne promet donc rien qu'on ne tienne.
            if (ev.type === 'titre' && ev.cible_id) {
                bloc.classList.add('est-cliquable');
                bloc.addEventListener('click', () => {
                    sessionStorage.setItem('titre_id', String(ev.cible_id));
                    navigateTo('library/titre');
                });
            }

            return bloc;
        }

        function ajouter(evenements) {
            const frag = document.createDocumentFragment();

            evenements.forEach(ev => {
                const date = new Date(ev.ts * 1000);
                const jour = date.toDateString();

                if (jour !== jourCourant) {
                    jourCourant = jour;
                    const sep = document.createElement('div');
                    sep.className = 'historique-jour';
                    sep.textContent = nomDuJour(date);
                    frag.appendChild(sep);
                }

                frag.appendChild(ligne(ev));
            });

            corps.appendChild(frag);
        }

        async function chargerSuite() {
            // Une seule requête à la fois : le défilement peut faire entrer la
            // sentinelle plusieurs fois avant l'arrivée de la réponse.
            if (enCours || termine) return;
            enCours = true;
            etat.textContent = 'Chargement…';

            try {
                const res = await fetch(`actions/activite_lister.php?offset=${offset}&limite=${PAQUET}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Erreur');

                ajouter(data.evenements);
                offset += data.evenements.length;
                compteur.textContent = String(offset);

                if (data.evenements.length < PAQUET) {
                    termine = true;
                    observateur.disconnect();
                    etat.textContent = offset === 0
                        ? 'Rien à raconter pour le moment'
                        : "Début de l'activité";
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
         */
        const observateur = new IntersectionObserver((entrees) => {
            if (entrees.some(e => e.isIntersecting)) chargerSuite();
        }, { rootMargin: '400px' });

        observateur.observe(sentinelle);

        chargerSuite();
    })();
</script>
