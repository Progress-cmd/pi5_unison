<?php
/**
 * Fil d'activité : ce qui a changé dans l'application, et par qui.
 *
 * Chargé par paquets, comme l'historique d'écoute : le fil grandit à chaque
 * import, chaque note, chaque message, et n'a pas de plafond.
 */
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/rendu.php";
?>
<article id="activite-liste" class="containers">
    <div class="head-bar">Activité<span id="activite-compteur" class="more-bar"></span></div>
    <div class="body-bar" id="activite-corps"><?= squelettes(5) ?></div>

    <!-- Sentinelle : sa venue à l'écran déclenche le paquet suivant. -->
    <div id="activite-sentinelle"></div>
    <div id="activite-etat" class="titres-etat"></div>
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
            titre:          { icone: 'music_note',    verbe: 'a ajouté le titre',
                              groupe: n => `a ajouté ${n} titres` },
            album:          { icone: 'album',         verbe: "a importé l'album",
                              groupe: n => `a importé ${n} albums` },
            playlist:       { icone: 'queue_music',   verbe: 'a créé la playlist',
                              groupe: n => `a créé ${n} playlists` },
            note:           { icone: 'sticky_note_2', verbe: 'a écrit une note sur',
                              groupe: n => `a écrit ${n} notes` },
            artiste_favori: { icone: 'favorite',      verbe: 'a mis en favori',
                              groupe: n => `a mis ${n} artistes en favori` },
            message:        { icone: 'forum',         verbe: 'a écrit',
                              groupe: n => `a écrit ${n} messages` },
        };

        // En dessous, la ligne groupée coûterait un clic pour gagner deux
        // lignes : on laisse le détail.
        const SEUIL_GROUPE = 3;

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

        /*
         * Ligne unique pour une suite d'événements identiques.
         *
         * Un import de 59 titres produisait 59 lignes « Francis a ajouté le
         * titre … » : le fil ne racontait plus rien, et tout ce qui s'était
         * passé avant était repoussé hors de l'écran. La suite est repliée en
         * une ligne, que l'on peut déplier — l'information n'est pas perdue,
         * elle est juste rangée.
         */
        function ligneGroupe(suite) {
            const type = TYPES[suite.type] || { icone: 'bolt', groupe: n => `a fait ${n} choses` };
            const n = suite.evenements.length;

            const bloc = document.createElement('div');
            bloc.className = 'activite-groupe';

            const entete = document.createElement('button');
            entete.type = 'button';
            entete.className = 'activite-ligne activite-groupe-entete';
            entete.setAttribute('aria-expanded', 'false');

            const icone = document.createElement('span');
            icone.className = 'material-symbols-outlined activite-icone';
            icone.textContent = type.icone;

            const texte = document.createElement('div');
            texte.className = 'activite-texte';

            const phrase = document.createElement('div');
            const acteur = document.createElement('b');
            acteur.textContent = suite.acteur;
            phrase.append(acteur, document.createTextNode(' ' + type.groupe(n)));

            const quand = document.createElement('div');
            quand.className = 'activite-heure';
            /*
             * Les événements arrivent du plus récent au plus ancien : le
             * dernier du tableau est le plus ancien. On affiche l'intervalle,
             * sauf s'il tient dans la même minute.
             */
            const debut = heure(new Date(suite.evenements[n - 1].ts * 1000));
            const fin = heure(new Date(suite.evenements[0].ts * 1000));
            quand.textContent = debut === fin ? fin : debut + ' – ' + fin;

            const chevron = document.createElement('span');
            chevron.className = 'material-symbols-outlined activite-chevron';
            chevron.textContent = 'expand_more';

            texte.append(phrase, quand);
            entete.append(icone, texte, chevron);

            const detail = document.createElement('div');
            detail.className = 'activite-groupe-detail';
            detail.hidden = true;

            let rempli = false;
            entete.addEventListener('click', () => {
                // Les lignes ne sont construites qu'à la première ouverture :
                // un import de plusieurs centaines de titres n'a pas à peser
                // sur l'affichage de ceux qui ne le déplieront jamais.
                if (!rempli) {
                    suite.evenements.forEach(ev => detail.appendChild(ligne(ev)));
                    rempli = true;
                }
                const ouvert = detail.hidden;
                detail.hidden = !ouvert;
                entete.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
                chevron.textContent = ouvert ? 'expand_less' : 'expand_more';
            });

            bloc.append(entete, detail);
            return bloc;
        }

        /** Pose le séparateur de jour si on vient d'en changer. */
        function separateurJour(frag, date) {
            const jour = date.toDateString();
            if (jour === jourCourant) return;
            jourCourant = jour;
            const sep = document.createElement('div');
            sep.className = 'historique-jour';
            sep.textContent = nomDuJour(date);
            frag.appendChild(sep);
        }

        function rendreSuite(frag, suite) {
            separateurJour(frag, new Date(suite.evenements[0].ts * 1000));

            if (suite.evenements.length >= SEUIL_GROUPE) {
                frag.appendChild(ligneGroupe(suite));
            } else {
                suite.evenements.forEach(ev => frag.appendChild(ligne(ev)));
            }
        }

        /*
         * Suite en cours, non encore affichée.
         *
         * Elle attend le paquet suivant parce qu'une série peut être coupée
         * par la pagination : sans ça, un import de 59 titres s'affichait en
         * « 30 titres » puis « 29 titres », ce qui est exactement ce qu'on
         * cherchait à éviter.
         */
        let enAttente = null;

        function ajouter(evenements, dernierPaquet) {
            const frag = document.createDocumentFragment();

            evenements.forEach(ev => {
                const jour = new Date(ev.ts * 1000).toDateString();

                const memeSuite = enAttente
                    && enAttente.acteur === ev.acteur
                    && enAttente.type === ev.type
                    && enAttente.jour === jour;

                if (memeSuite) {
                    enAttente.evenements.push(ev);
                    return;
                }

                if (enAttente) rendreSuite(frag, enAttente);
                enAttente = { acteur: ev.acteur, type: ev.type, jour, evenements: [ev] };
            });

            // Le dernier paquet n'a pas de suite : plus rien ne viendra
            // prolonger la série en cours.
            if (dernierPaquet && enAttente) {
                rendreSuite(frag, enAttente);
                enAttente = null;
            }

            corps.appendChild(frag);
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
                const res = await fetch(`actions/activite_lister.php?offset=${offset}&limite=${PAQUET}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Erreur');

                corps.querySelectorAll('.squelette-ligne').forEach(e => e.remove());
                ajouter(data.evenements, data.evenements.length < PAQUET);
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

            /*
             * L'observateur ne signale que les *changements* d'intersection :
             * si la sentinelle était déjà visible et le reste, il ne dit plus
             * rien. Un paquet trop court pour remplir la fenêtre laisserait
             * alors la page à moitié vide sans rien charger de plus — d'autant
             * que les suites groupées occupent peu de hauteur. On relance donc
             * tant que le pied reste atteint.
             */
            if (!termine && piedAtteint()) {
                requestAnimationFrame(chargerSuite);
            }
        }

        /*
         * IntersectionObserver plutôt qu'un écouteur de défilement : la page
         * est injectée par le routeur et ne sait pas quel conteneur défile.
         */

        /**
         * Le pied de la liste est-il vraiment atteint ?
         *
         * L'observateur ne suffit pas seul. Il se déclenche à chaque
         * changement de géométrie, pas seulement au défilement : déplier une
         * suite groupée en est un. Le fil chargeait alors un paquet de plus à
         * chaque ouverture, et remontait de plus en plus loin dans le temps
         * sans que personne ne l'ait demandé — d'autant que les suites
         * repliées tiennent peu de place et gardent la sentinelle à portée.
         *
         * On vérifie donc la distance réelle au bas du conteneur qui défile.
         * Quand on déplie, le contenu grandit et cette distance augmente :
         * plus de chargement. Quand la liste est plus courte que la fenêtre,
         * elle vaut zéro et les paquets s'enchaînent normalement pour la
         * remplir.
         */
        function piedAtteint() {
            const boite = corps.closest('#main-content') || document.scrollingElement;
            return boite.scrollHeight - boite.scrollTop - boite.clientHeight < 400;
        }

        const observateur = new IntersectionObserver((entrees) => {
            if (entrees.some(e => e.isIntersecting) && piedAtteint()) chargerSuite();
        }, { rootMargin: '400px' });

        observateur.observe(sentinelle);

        chargerSuite();
    })();
</script>
