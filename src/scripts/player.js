(function() {
    const player = document.getElementById('player');
    const closeBtn = document.getElementById('close-button');
    const extend = document.getElementById('extend');
    let currentTrackId = null;
    // Nom du titre en cours : les modales d'options l'affichent, sinon on ne
    // sait pas sur quel morceau on agit quand elles s'ouvrent depuis une liste.
    let titreCourant = '';

    // --- Suivi d'écoute ---
    let tempsLectureTitre = 0;   // secondes réelles écoutées sur le titre courant (seuil d'écoute)
    let secondesAFlusher = 0;    // secondes accumulées pas encore envoyées au serveur
    let ecouteComptee = false;   // une seule écoute comptée par chargement / tour de boucle
    let dernierTemps = 0;        // dernier currentTime vu au timeupdate
    let dernierePositionPubliee = 0; // dernière position annoncée au système (notification)
    window.sourcePlaylistId = null; // playlist d'origine de la queue (null = hors playlist)

    // --- Audio setup ---
    const audio = new Audio();

    /*
     * Exposé en lecture pour qui doit savoir si un son joue — la présence
     * partagée, aujourd'hui. L'élément n'est pas dans le DOM (new Audio),
     * donc introuvable autrement ; et reconstituer l'état ailleurs, à partir
     * des seuls événements, produirait une seconde vérité qui dériverait.
     */
    window.unisonAudio = audio;

    /*
     * Compteur de reprises après échec de chargement. Déclaré ici, et non
     * près du gestionnaire qui s'en sert : `reinitialiserMesures()` les remet
     * à zéro et peut tourner pendant l'initialisation, donc avant que le bloc
     * plus bas ait été évalué. En `let` déclaré là-bas, c'était une zone
     * morte temporelle.
     */
    let reprisesFaites = 0;
    let minuteurReprise = 0;
    let abandonsConsecutifs = 0;
    let lectureAbandonnee = false;

    /*
     * Réglages propres à l'appareil (scripts/prefs.js) : volume mémorisé,
     * reprise, aléatoire par défaut. L'objet peut manquer si le script n'a pas
     * chargé — tout ce qui suit le tolère et retombe sur le comportement
     * d'origine.
     */
    const prefs = window.unisonPrefs;

    // Volume retenu de la dernière fois, avant toute lecture.
    if (prefs && prefs.lire('memoriserVolume')) {
        const v = prefs.volume();
        if (v !== null) audio.volume = v;
    }

    /** Retient le volume courant, si l'utilisateur l'a demandé. */
    function retenirVolume() {
        if (prefs && prefs.lire('memoriserVolume')) prefs.poserVolume(audio.volume);
    }

    audio.addEventListener('volumechange', retenirVolume);

    /*
     * Un seul drapeau sur <body> dit « ça joue ». Le CSS s'en sert pour faire
     * respirer la pochette et animer la vague de la ligne en cours, où qu'elle
     * soit dans la page — la poser sur le lecteur n'aurait pas permis
     * d'atteindre les listes.
     */
    function majDrapeauLecture() {
        document.body.classList.toggle('lecture-en-cours', !audio.paused && !audio.ended);
    }

    ['play', 'pause', 'ended', 'emptied'].forEach(e =>
        audio.addEventListener(e, majDrapeauLecture));

    /*
     * Position à restaurer sur le prochain titre chargé, en secondes.
     * Posée par la reprise, consommée par `loadedmetadata` : avant que la
     * durée soit connue, écrire `currentTime` n'a aucun effet.
     */
    let positionAReprendre = 0;
    let derniereSauvegarde = 0;

    audio.addEventListener('loadedmetadata', () => {
        if (positionAReprendre > 0 && audio.duration
            && positionAReprendre < audio.duration - 1) {
            audio.currentTime = positionAReprendre;
        }
        positionAReprendre = 0;
    });

    /** Note où on en est, pour pouvoir y revenir au prochain démarrage. */
    function retenirPosition() {
        if (!prefs || !prefs.lire('reprise') || !currentTrackId) return;
        prefs.poserDerniereEcoute(currentTrackId, audio.currentTime);
    }

    // Une fin de titre remet le compteur à zéro : reprendre à la dernière
    // seconde d'un morceau terminé n'aurait aucun sens.
    audio.addEventListener('ended', () => {
        if (prefs && prefs.lire('reprise')) prefs.poserDerniereEcoute(currentTrackId, 0);
    });
    audio.addEventListener('pause', retenirPosition);

    /* ---------- Onde vivante de la barre de progression ---------- */

    /*
     * Les barres bougent avec le son, comme dans les applications de musique.
     *
     * Un AnalyserNode lit ce qui sort vraiment du lecteur : ce n'est pas une
     * animation décorative posée par-dessus, ce sont les fréquences du morceau
     * en train de jouer. À l'arrêt, les barres retombent sur la forme
     * pré-calculée du titre (includes/ondeAudio.php) — la pause montre alors
     * le morceau entier, au lieu d'une rangée morte.
     *
     * Deux couches identiques se superposent : la grise en fond, la colorée
     * rognée à la position de lecture. Les deux reçoivent les mêmes hauteurs à
     * chaque image, ce qui laisse le rognage dire la progression.
     */
    const ONDE_BARRES = 48;

    let ondeRepos = null;     // forme pré-calculée, ramenée à ONDE_BARRES
    let ondeNiveaux = new Array(ONDE_BARRES).fill(0);
    let ondeImage = null;     // identifiant de requestAnimationFrame
    let analyseur = null;
    let contexteAudio = null;
    let analyseTentee = false;
    let silences = 0;          // images consécutives sans le moindre signal
    let spectre = null;
    let bandes = null;        // bornes des intervalles logarithmiques

    /**
     * Branche l'analyseur sur l'élément audio, une seule fois.
     *
     * Un AudioContext naît suspendu s'il n'est pas créé pendant un geste de
     * l'utilisateur, et les navigateurs mobiles refusent de le reprendre en
     * dehors d'un geste. D'où les deux points d'entrée : le premier `play`,
     * et le premier contact avec la page (voir plus bas). Sur téléphone c'est
     * le second qui sauve la mise — l'événement `play` arrive après un
     * `await fetch()`, et le jeton de geste a souvent expiré entre-temps.
     *
     * En cas d'échec — API absente, contexte refusé — on repart sur une
     * animation de secours plutôt que de laisser la barre inerte.
     */
    function brancherAnalyseur() {
        if (analyseTentee) return;
        analyseTentee = true;

        /*
         * L'analyseur tourne aussi sur mobile.
         *
         * Il en avait été écarté un temps, sur le soupçon que le détour par
         * un AudioContext y coupait le son. C'était faux : la coupure venait
         * d'un déverrouillage audio ajouté au même moment, qui rappelait
         * pause() après la lecture de l'utilisateur. Le soupçon n'était pas
         * absurde — createMediaElementSource() détourne définitivement la
         * sortie de l'élément, et un contexte resté suspendu rendrait bien
         * muet — mais ce n'était pas ce qui se passait.
         *
         * Les garde-fous d'origine suffisent : le contexte est créé pendant
         * un geste, repris à chaque geste suivant, et niveauxCibles() bascule
         * sur l'animation de secours si l'analyseur reste muet vingt images
         * de suite.
         */
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;

        try {
            const ctx = new Ctx();
            const source = ctx.createMediaElementSource(audio);
            const an = ctx.createAnalyser();

            an.fftSize = 1024;            // 512 bandes de fréquence
            an.smoothingTimeConstant = 0.72;

            /*
             * La sortie doit être rebranchée sur les haut-parleurs : passer par
             * un AudioContext détourne le son de l'élément, et l'oublier le
             * rendrait muet. C'est le piège classique de cette API.
             */
            source.connect(an);
            an.connect(ctx.destination);

            ctx.resume().catch(() => {});

            analyseur = an;
            contexteAudio = ctx;
            spectre = new Uint8Array(an.frequencyBinCount);

            /*
             * Découpage logarithmique, calculé une fois.
             *
             * L'oreille entend les hauteurs en octaves, pas en hertz : entre
             * 100 et 200 Hz il y a autant de musique qu'entre 5 000 et
             * 10 000 Hz. Un découpage linéaire entasse donc presque tout le
             * morceau dans les premières barres et laisse le reste à plat —
             * c'est ce qui donnait un analyseur de laboratoire au lieu d'un
             * visualiseur. Ici chaque barre couvre un intervalle musical
             * constant.
             */
            const premiere = 2;                      // ~40 Hz, sous le grave utile
            const derniere = Math.floor(an.frequencyBinCount * 0.42);   // ~9 kHz
            bandes = new Array(ONDE_BARRES + 1).fill(0).map((_, i) =>
                Math.round(premiere * Math.pow(derniere / premiere, i / ONDE_BARRES)));
        } catch (e) {
            // Déjà branché, ou contexte refusé : l'animation de secours prend
            // le relais. Le son, lui, n'a pas été touché.
            analyseur = null;
        }
    }

    /** Hauteurs cibles : le son en cours, ou une houle de secours. */
    function niveauxCibles() {
        if (analyseur && spectre && bandes) {
            analyseur.getByteFrequencyData(spectre);

            /*
             * Analyseur muet alors que le son joue : le contexte est resté
             * suspendu, ou le navigateur refuse de router l'élément. On
             * bascule alors sur la houle de secours plutôt que d'afficher une
             * rangée de barres à plat — c'est ce qui se passait sur téléphone.
             */
            let total = 0;
            for (let j = 0; j < spectre.length; j++) total += spectre[j];

            if (total === 0) {
                if (++silences > 20) analyseur = null;
                return ondeNiveaux.slice();
            }
            silences = 0;

            return ondeNiveaux.map((_, i) => {
                const debut = bandes[i];
                const fin = Math.max(debut + 1, bandes[i + 1]);

                // La crête de l'intervalle, pas sa moyenne : une note qui perce
                // doit faire bondir sa barre, pas se diluer dans ses voisines.
                let crete = 0;
                for (let j = debut; j < fin && j < spectre.length; j++) {
                    if (spectre[j] > crete) crete = spectre[j];
                }

                /*
                 * Courbe de contraste, puis léger gain vers les aigus.
                 *
                 * Les données de fréquence sont déjà comprimées par le
                 * navigateur : appliquer un gain seul saturait toutes les
                 * barres au plafond, et le tracé devenait un bloc plein. La
                 * puissance creuse les écarts — les bandes faibles retombent,
                 * les crêtes ressortent — ce qui rend le mouvement lisible.
                 */
                const gain = 0.95 + (i / ONDE_BARRES) * 0.55;
                return Math.min(1, Math.pow(crete / 255, 1.8) * gain);
            });
        }

        // Secours : une houle entretenue, sans rapport avec le son mais vivante.
        const t = performance.now() / 1000;
        return ondeNiveaux.map((_, i) =>
            0.35 + 0.3 * Math.sin(t * 3 + i * 0.45) + 0.15 * Math.sin(t * 5.3 + i * 0.9));
    }

    function appliquerNiveaux() {
        const fond = document.querySelectorAll('#extend .player-onde--fond i');
        const lu = document.querySelectorAll('#extend .player-onde--lu i');
        if (!fond.length) return;

        for (let i = 0; i < ondeNiveaux.length; i++) {
            const h = Math.max(6, Math.min(100, Math.round(ondeNiveaux[i] * 100))) + '%';
            if (fond[i]) fond[i].style.height = h;
            if (lu[i]) lu[i].style.height = h;
        }
    }

    function boucleOnde() {
        const cibles = niveauxCibles();

        /*
         * Lissage : sans lui les barres sautent d'une image à l'autre et
         * l'ensemble scintille. La montée est plus vive que la descente, pour
         * que les attaques se voient et que la retombée reste douce.
         */
        for (let i = 0; i < ondeNiveaux.length; i++) {
            const c = cibles[i];
            const f = c > ondeNiveaux[i] ? 0.55 : 0.12;
            ondeNiveaux[i] += (c - ondeNiveaux[i]) * f;
        }

        appliquerNiveaux();
        ondeImage = requestAnimationFrame(boucleOnde);
    }

    function demarrerOnde() {
        if (ondeImage !== null) return;
        if (!document.querySelector('#extend .player-onde')) return;
        brancherAnalyseur();
        ondeImage = requestAnimationFrame(boucleOnde);
    }

    function arreterOnde() {
        if (ondeImage === null) return;
        cancelAnimationFrame(ondeImage);
        ondeImage = null;

        /*
         * Retour au repos en douceur : les barres rejoignent la forme du
         * morceau plutôt que de se figer là où la musique les a laissées.
         */
        let pas = 0;
        const repos = () => {
            let bouge = false;

            for (let i = 0; i < ondeNiveaux.length; i++) {
                const c = ondeRepos ? ondeRepos[i] : 0.12;
                ondeNiveaux[i] += (c - ondeNiveaux[i]) * 0.18;
                if (Math.abs(c - ondeNiveaux[i]) > 0.01) bouge = true;
            }

            appliquerNiveaux();
            if (bouge && ++pas < 90 && ondeImage === null) requestAnimationFrame(repos);
        };
        requestAnimationFrame(repos);
    }

    /**
     * Construit les barres pour le titre courant.
     *
     * `onde` est la forme pré-calculée en base64 (120 valeurs). Elle sert de
     * position de repos ; absente, les barres reposent à plat.
     */
    function dessinerOnde(onde) {
        const barre = document.querySelector('#extend .player-progress_bar');
        if (!barre) return;

        arreterOnde();
        barre.querySelectorAll('.player-onde').forEach(e => e.remove());

        ondeRepos = null;
        try {
            if (onde) {
                const brut = atob(onde);
                const src = Array.from(brut, ch => ch.charCodeAt(0) / 255);

                // Ramenée de 120 à ONDE_BARRES par moyenne de tranche : prendre
                // une valeur sur deux ferait clignoter le repos d'un titre à
                // l'autre selon l'endroit où tombe l'échantillon.
                ondeRepos = new Array(ONDE_BARRES).fill(0).map((_, i) => {
                    const d = Math.floor(i * src.length / ONDE_BARRES);
                    const f = Math.max(d + 1, Math.floor((i + 1) * src.length / ONDE_BARRES));
                    const tranche = src.slice(d, f);
                    // Réduite : au repos la forme s'indique, elle ne s'impose pas.
                    return tranche.reduce((a, b) => a + b, 0) / tranche.length * 0.55;
                });
            }
        } catch (e) {
            ondeRepos = null;
        }

        const couche = (classe) => {
            const c = document.createElement('div');
            c.className = 'player-onde ' + classe;
            const frag = document.createDocumentFragment();
            for (let i = 0; i < ONDE_BARRES; i++) frag.appendChild(document.createElement('i'));
            c.appendChild(frag);
            return c;
        };

        barre.append(couche('player-onde--fond'), couche('player-onde--lu'));
        barre.classList.add('a-onde');

        ondeNiveaux = new Array(ONDE_BARRES).fill(0);
        appliquerNiveaux();
        majOnde(0);

        if (!audio.paused) demarrerOnde();
    }

    /** Avance le rognage de la couche colorée. */
    function majOnde(pct) {
        const lu = document.querySelector('#extend .player-onde--lu');
        if (lu) lu.style.clipPath = 'inset(0 ' + (100 - pct) + '% 0 0)';
    }

    /*
     * Premier contact avec la page : on crée le contexte pendant que le geste
     * est encore valide, et on le reprend s'il s'était assoupi. Les
     * navigateurs mobiles suspendent l'AudioContext dès que la page perd le
     * premier plan, d'où la reprise à chaque geste et non seulement au
     * premier.
     */
    ['pointerdown', 'touchstart', 'keydown'].forEach(evt =>
        document.addEventListener(evt, () => {
            brancherAnalyseur();
            if (contexteAudio && contexteAudio.state === 'suspended') {
                contexteAudio.resume().catch(() => {});
            }
        }, { capture: true, passive: true }));

    audio.addEventListener('play', demarrerOnde);
    ['pause', 'ended', 'emptied'].forEach(e => audio.addEventListener(e, arreterOnde));

    /*
     * Onglet masqué : requestAnimationFrame s'arrête de lui-même, mais on coupe
     * explicitement pour ne pas laisser une boucle en attente sur un téléphone
     * dont l'écran vient de s'éteindre.
     */
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            if (!audio.paused) demarrerOnde();
        } else {
            arreterOnde();
        }
    });

    /** Mélange une liste sans toucher à l'originale (Fisher-Yates). */
    function melanger(liste) {
        const copie = liste.slice();
        for (let i = copie.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [copie[i], copie[j]] = [copie[j], copie[i]];
        }
        return copie;
    }

    // Envoie les secondes réellement écoutées au serveur (par lots)
    function flusherTemps(avecBeacon = false) {
        const s = Math.floor(secondesAFlusher);
        if (s < 1) return;
        secondesAFlusher -= s;
        // sendBeacon ne passe pas par fetch : le jeton est posé à la main.
        const corps = new URLSearchParams({ secondes: s });
        if (window.ajouterJetonCsrf) window.ajouterJetonCsrf(corps);
        if (avecBeacon && navigator.sendBeacon) {
            navigator.sendBeacon('actions/ajouter_temps_ecoute.php', corps);
        } else {
            fetch('actions/ajouter_temps_ecoute.php', { method: 'POST', body: corps, keepalive: true }).catch(() => {});
        }
    }

    window.addEventListener('pagehide', () => flusherTemps(true));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') flusherTemps(true);
    });

    /*
     * La permission média n'est plus demandée ici.
     *
     * Elle ne sert qu'à afficher le NOM des sorties audio dans les réglages —
     * une fonction de bureau, indisponible sur mobile. La réclamer au
     * chargement affichait une demande d'accès au micro à chaque visite, y
     * compris sur Android où elle ne pouvait servir à rien. Elle est désormais
     * demandée à l'ouverture des réglages, et seulement si le navigateur sait
     * changer de sortie (voir enumerateAudioDevices).
     */

    // --- Charge une piste par ID et met à jour le player ---
    /*
     * Fiches de titres déjà connues, pour enchaîner sans aller-retour réseau.
     *
     * C'est ce qui permet à la lecture de continuer en arrière-plan. Sans ce
     * cache, la fin d'un morceau déclenchait un `await fetch(getTrack.php)`
     * avant de pouvoir poser la source suivante : pendant ce trou, l'élément
     * n'avait plus rien à jouer. Le système en conclut que la lecture est
     * terminée, libère le focus audio et retire la notification — et le
     * play() qui arrivait ensuite, page en arrière-plan et sans geste, était
     * refusé. D'où un lecteur qui s'arrêtait après chaque titre dès qu'on
     * quittait l'application.
     *
     * Borné : une file peut compter des centaines de titres, et on ne garde
     * que ce qui sert à enchaîner.
     */
    const cacheTitres = new Map();
    const CACHE_TITRES_MAX = 30;

    function memoriserTitre(track) {
        if (!track || track.id === null) return;
        cacheTitres.set(track.id, track);
        if (cacheTitres.size > CACHE_TITRES_MAX) {
            cacheTitres.delete(cacheTitres.keys().next().value);
        }
    }

    async function recupererTitre(id) {
        const connu = cacheTitres.get(id);
        if (connu) return connu;

        try {
            const res = await fetch(`actions/getTrack.php?id=${id}`);
            const track = res.ok ? await res.json() : null;
            memoriserTitre(track);
            return track;
        } catch (e) {
            return null;
        }
    }

    /**
     * Prépare la fiche du titre suivant pendant que le morceau en cours joue.
     *
     * Appelé au démarrage d'une lecture : on a alors plusieurs minutes devant
     * soi, et la requête se fait pendant que la page est encore au premier
     * plan, donc sans throttling.
     */
    function prechargerSuivant() {
        if (!window.waitPlaylist) return;
        const suivant = window.waitPlaylist[window.currentIndex + 1];
        if (!suivant || cacheTitres.has(suivant.id)) return;
        if (window.__traceAudio) window.__traceAudio('préchargement lancé', 'titre ' + suivant.id);
        recupererTitre(suivant.id).then((t) => {
            if (window.__traceAudio) {
                window.__traceAudio(t ? 'préchargement abouti' : 'préchargement ÉCHOUÉ',
                                    'titre ' + suivant.id);
            }
        });
    }

    /** Pose un titre déjà connu sur le lecteur. Volontairement synchrone. */
    function appliquerTitre(track, autoplay) {
        audio.src = track.src;

        titreCourant = track.title;
        document.querySelector('#retract .title-info').textContent = track.title;
        document.querySelector('#retract  .artist-info').textContent = track.artist;
        document.querySelector('#extend .title-info').textContent = track.title;
        document.querySelector('#extend  .artist-info').textContent = track.artist;
        document.getElementById('player-img').src = track.img;
        document.getElementById('player-img').alt = `${track.title} - ${track.artist}`;

        document.querySelector('.player-progress_current').style.width = '0%';
        document.querySelector('.time-current').textContent = '0:00';
        document.querySelector('.time-total').textContent = formatTime(track.duration);

        /*
         * Publication auprès du système AVANT le chargement : la notification
         * affiche ainsi le bon titre dès l'instant où la lecture démarre,
         * plutôt que de garder brièvement celui de la piste précédente.
         */
        majMetadonneesMedia(track);

        // Onde du nouveau titre : redessinée à chaque chargement, parce que
        // chaque morceau a la sienne.
        dessinerOnde(track.onde);

        audio.load();

        /*
         * play() demandé directement, et non depuis un gestionnaire canplay.
         *
         * C'était la cause du silence sur téléphone. canplay est un événement
         * média : il arrive bien après le geste qui a lancé le morceau, et à
         * ce moment-là il n'y a plus d'activation utilisateur. Firefox, qui
         * est strict, refuse alors la lecture AUDIBLE et l'autorise muette —
         * d'où le symptôme exact rapporté : ça ne démarre que si le volume de
         * l'application est à zéro. Chrome sur ordinateur laisse passer une
         * fois le site « fréquenté », ce qui masquait le problème.
         *
         * Un play() sur un élément pas encore prêt est parfaitement valide :
         * le navigateur démarre dès qu'il a de quoi jouer.
         */
        if (autoplay) demanderLecture();

        updateSelected();
        prechargerSuivant();
    }

    function reinitialiserMesures(id) {
        flusherTemps();
        clearTimeout(minuteurReprise);
        reprisesFaites = 0;
        lectureAbandonnee = false;
        tempsLectureTitre = 0;
        ecouteComptee = false;
        dernierTemps = 0;
        dernierePositionPubliee = 0;
        currentTrackId = id;
        titreCourant = '';
    }

    function titreIntrouvable() {
        currentTrackId = null;
        if (window.showToast) window.showToast('Ce titre est introuvable', 'error', 6000);
    }

    /*
     * Deux chemins, et la distinction compte.
     *
     * Titre déjà connu : tout se fait dans la foulée, sans `await`. C'est
     * indispensable à l'enchaînement en arrière-plan — la source suivante est
     * posée dans le même tour de boucle que l'événement `ended`, sans laisser
     * au système le temps de croire que la lecture est finie.
     *
     * Titre inconnu (première lecture, saut dans la file) : on passe par le
     * réseau, comme avant.
     */
    function loadTrack(id, autoplay = true) {
        reinitialiserMesures(id);

        const connu = cacheTitres.get(id);
        if (connu) {
            if (window.__traceAudio) window.__traceAudio('titre ' + id + ' : CACHE (synchrone)');
            appliquerTitre(connu, autoplay);
            return;
        }

        if (window.__traceAudio) window.__traceAudio('titre ' + id + ' : RÉSEAU (asynchrone)');
        recupererTitre(id).then((track) => {
            /*
             * La file a pu bouger pendant la requête — piste suivante pressée
             * deux fois, par exemple. Appliquer une fiche périmée ramènerait
             * le lecteur en arrière.
             */
            if (currentTrackId !== id) return;
            if (!track || track.id === null) { titreIntrouvable(); return; }
            appliquerTitre(track, autoplay);
        });
    }

    window.loadTrack = loadTrack;

    /** Le player n'a rien à lire : on le dit, au lieu de rester sur « Loading ». */
    function afficherFileVide() {
        document.querySelectorAll('.title-info').forEach(el => el.textContent = 'Aucun titre');
        document.querySelectorAll('.artist-info').forEach(el => el.textContent = "File d'attente vide");
    }

    function appliquerFileAttente(playlist) {
        if (!playlist || playlist.length === 0) {
            if (!currentTrackId) afficherFileVide();
            return;
        }

        /*
         * Lecture aléatoire par défaut : la file est mélangée à son arrivée,
         * et seulement si rien ne joue encore. Mélanger une file en cours
         * d'écoute déplacerait le titre courant sous les pieds de l'auditeur.
         */
        if (!currentTrackId && prefs && prefs.lire('aleatoire') && playlist.length > 1) {
            playlist = melanger(playlist);
        }

        window.waitPlaylist = playlist;

        if (!currentTrackId) {
            /*
             * Reprise : on repart du titre laissé en plan s'il est encore dans
             * la file. Sinon on prend le premier, comme avant — un titre
             * supprimé ou une file changée ne doit pas laisser le player muet.
             */
            let depart = 0;
            const derniere = prefs && prefs.lire('reprise') ? prefs.derniereEcoute() : null;

            if (derniere) {
                const i = playlist.findIndex(t => Number(t.id) === derniere.id);
                if (i !== -1) {
                    depart = i;
                    positionAReprendre = derniere.position;
                }
            }

            loadTrack(playlist[depart]['id'], false);
            window.currentIndex = depart;
        } else {
            const idx = playlist.findIndex(t => t.id == currentTrackId);
            window.currentIndex = idx !== -1 ? idx : 0;
            updateSelected();
        }
    }

    // La page d'accueil injecte la file directement dans la page.
    window.addEventListener('playlistReady', (e) => appliquerFileAttente(e.detail.playlist));

    /*
     * Ouverture de l'application ailleurs qu'à l'accueil : personne n'a alors
     * fourni la file d'attente, et le player n'avait aucun titre à lancer.
     * Il va donc la chercher lui-même.
     *
     * Si la page d'accueil répond entre-temps, c'est elle qui gagne : on
     * n'applique le résultat que si la file est toujours vide à l'arrivée de
     * la réponse. L'ordre des deux sources n'a donc pas d'importance.
     */
    (async function chargerFileInitiale() {
        try {
            const res = await fetch('actions/get_queue.php');
            if (!res.ok) return;

            const data = await res.json();
            if (!window.waitPlaylist || window.waitPlaylist.length === 0) {
                appliquerFileAttente(data.tracks || []);
                // Prévient les pages déjà affichées qui dépendent de la file
                // (la page « Liste d'attente », ouverte directement).
                window.dispatchEvent(new CustomEvent('queueReady'));
            }
        } catch (e) {
            // Sans file d'attente le player reste inerte, comme avant :
            // ce n'est pas la peine d'alerter l'utilisateur.
        }
    })();

    player.addEventListener('click', function(e) {
        if (e.target.closest('button, .player-progress_bar')) return;
        extend.style.visibility = '';
        extend.classList.remove('closing');
        extend.classList.add('expanded');
    });

    closeBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        extend.classList.remove('expanded');
        extend.classList.add('closing');
        extend.addEventListener('animationend', () => {
            extend.classList.remove('closing');
            extend.style.visibility = 'hidden';
        }, { once: true });
    });

    /*
     * Lecture demandée, avec le refus rendu visible.
     *
     * Le rejet partait dans un catch vide : quand le navigateur refusait, il
     * ne restait aucune trace, ni pour l'utilisateur ni dans la console.
     */
    function demanderLecture() {
        const p = audio.play();
        if (!p || !p.catch) return;

        p.catch((err) => {
            if (err && err.name === 'NotAllowedError') {
                if (window.__traceAudio) window.__traceAudio('play() REFUSÉ', 'NotAllowedError');
                if (window.showToast) {
                    window.showToast(
                        "Le navigateur demande un appui pour lancer le son — touchez « lecture ».",
                        'error', 6000
                    );
                }
                return;
            }
            if (window.__traceAudio) {
                window.__traceAudio('play() REFUSÉ', (err && err.name) + ' : ' + (err && err.message));
            }
            console.warn('lecture refusée :', err && err.name, err && err.message);
        });
    }

    function updatePlayBtns() {
        const icon = audio.paused ? 'play_arrow' : 'pause';
        document.querySelectorAll('.play-button').forEach(el => {
            el.textContent = icon;
        });
    }

    document.querySelectorAll('.play-button').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            audio.paused ? demanderLecture() : audio.pause();
        });
    });

    /*
     * Barre d'espace : lecture / pause.
     *
     * Le raccourci ne doit se déclencher que quand l'espace n'a rien d'autre à
     * faire. Deux cas à écarter, et ils arrivent en permanence dans cette
     * application :
     *
     *   - la saisie de texte. Un espace tapé dans le chat, dans la recherche
     *     ou dans le champ d'un genre doit s'écrire, pas mettre la musique en
     *     pause. D'où le test sur l'élément qui a le focus, contentEditable
     *     compris — le chat pourrait en utiliser un ;
     *   - les boutons et les cases à cocher. L'espace y vaut « activer » :
     *     l'intercepter ferait deux actions d'un seul appui.
     *
     * preventDefault() dans les autres cas, sinon la page défile d'un écran à
     * chaque appui, ce qui est le comportement par défaut de l'espace.
     */
    document.addEventListener('keydown', (e) => {
        if (e.code !== 'Space' && e.key !== ' ') return;
        if (e.ctrlKey || e.metaKey || e.altKey) return;

        const cible = e.target;
        if (cible instanceof HTMLInputElement
            || cible instanceof HTMLTextAreaElement
            || cible instanceof HTMLSelectElement
            || cible instanceof HTMLButtonElement
            || (cible && cible.isContentEditable)) {
            return;
        }

        // Rien de chargé : il n'y a rien à mettre en pause.
        if (!audio.src) return;

        e.preventDefault();
        audio.paused ? demanderLecture() : audio.pause();
    });

    /*
     * Trace de diagnostic, désactivée par défaut.
     *
     *     ?trace=audio   arme la trace et vide le journal
     *     ?trace=voir    affiche le journal (marche même trace éteinte)
     *     ?trace=off     éteint
     *
     * Elle ne corrige rien : elle nomme QUI a arrêté la lecture. Les toasts
     * de la version précédente ne servaient à rien pour le défaut qui reste —
     * la coupure arrive en fin de titre, application en arrière-plan, écran
     * éteint : personne ne regarde. Le journal est donc écrit dans
     * localStorage, et se relit après coup.
     *
     * `freeze`, `resume` et `wasDiscarded` viennent de l'API Page Lifecycle.
     * Ce sont eux qui tranchent l'hypothèse « le téléphone tue l'application
     * en arrière-plan » : si Android gèle l'onglet, ils le disent noir sur
     * blanc. S'ils n'apparaissent jamais et que la coupure a quand même lieu,
     * la cause est dans le code, pas dans le système.
     *
     * À retirer une fois la cause connue.
     */
    const TRACE_CLE = 'unison.trace.journal';
    const TRACE_MAX = 400;

    function traceLire() {
        try { return JSON.parse(localStorage.getItem(TRACE_CLE) || '[]'); }
        catch (e) { return []; }
    }

    /*
     * Rendu du journal dans la page, en texte sélectionnable.
     *
     * Pas de console : il n'y en a pas d'accessible sur un navigateur
     * Android. Le bouton « tout copier » existe parce que le but de ce
     * journal est d'être recopié ailleurs.
     */
    function traceAfficher() {
        const lignes = traceLire().map((l) => {
            const d = new Date(l.h);
            const hms = d.toTimeString().slice(0, 8) + '.'
                      + String(d.getMilliseconds()).padStart(3, '0');
            return hms + ' ' + l.v + ' ' + (l.p ? 'pause' : 'joue ')
                 + ' t=' + String(l.t).padStart(6) + '  ' + l.q
                 + (l.d ? ' — ' + l.d : '');
        });

        const boite = document.createElement('div');
        boite.setAttribute('style', [
            'position:fixed', 'inset:0', 'z-index:99999',
            'background:#111', 'color:#eee', 'font:12px/1.45 monospace',
            'display:flex', 'flex-direction:column'
        ].join(';'));

        const barre = document.createElement('div');
        barre.setAttribute('style', 'display:flex;gap:8px;padding:8px;flex:0 0 auto');

        const bouton = (texte, action) => {
            const b = document.createElement('button');
            b.textContent = texte;
            b.setAttribute('style', 'padding:8px 12px;font:inherit');
            b.addEventListener('click', action);
            barre.appendChild(b);
            return b;
        };

        const corps = document.createElement('pre');
        corps.setAttribute('style',
            'flex:1 1 auto;margin:0;padding:8px;overflow:auto;white-space:pre;user-select:text');
        corps.textContent = lignes.length
            ? lignes.join('\n')
            : 'Journal vide. Ouvrir « ?trace=audio », écouter, puis revenir ici.';

        bouton('Tout copier', () => {
            if (navigator.clipboard) navigator.clipboard.writeText(corps.textContent);
            else {
                const s = getSelection();
                const r = document.createRange();
                r.selectNodeContents(corps);
                s.removeAllRanges();
                s.addRange(r);
            }
        });
        bouton('Vider', () => {
            try { localStorage.removeItem(TRACE_CLE); } catch (e) {}
            corps.textContent = 'Journal vidé.';
        });
        bouton('Fermer', () => boite.remove());

        boite.appendChild(barre);
        boite.appendChild(corps);
        document.body.appendChild(boite);
    }

    (function tracerAudio() {
        let actif = false;
        let voir = false;
        try {
            const demande = new URLSearchParams(location.search).get('trace');
            if (demande === 'audio') {
                localStorage.setItem('unison.trace', '1');
                localStorage.removeItem(TRACE_CLE);
            }
            if (demande === 'off')  { localStorage.removeItem('unison.trace'); }
            if (demande === 'voir') { voir = true; }
            actif = localStorage.getItem('unison.trace') === '1';
        } catch (e) {}

        if (voir) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', traceAfficher);
            } else {
                traceAfficher();
            }
        }
        if (!actif) return;

        /*
         * Une écriture localStorage par ligne. C'est synchrone et donc cher,
         * mais on ne trace que des transitions : une poignée par titre. Ne
         * jamais brancher ça sur `timeupdate`.
         */
        function noter(quoi, detail) {
            const ligne = {
                h: Date.now(),
                q: quoi,
                d: detail || '',
                p: audio.paused ? 1 : 0,
                t: Number(audio.currentTime.toFixed(1)),
                v: (document.visibilityState || '?')[0]
            };
            try {
                const journal = traceLire();
                journal.push(ligne);
                while (journal.length > TRACE_MAX) journal.shift();
                localStorage.setItem(TRACE_CLE, JSON.stringify(journal));
            } catch (e) {}
            console.warn('[trace audio]', quoi, detail || '');
        }

        /*
         * Toasts conservés, mais seulement au premier plan : en arrière-plan
         * ils ne servent à personne et ralentissent la page au pire moment.
         */
        const noterEtDire = (quoi, detail) => {
            noter(quoi, detail);
            if (document.visibilityState === 'visible' && window.showToast) {
                window.showToast(quoi + (detail ? ' — ' + detail : ''), 'error', 6000);
            }
        };
        window.__traceAudio = noterEtDire;

        /*
         * Liste large, contrairement à la version précédente : le défaut se
         * joue pendant la bascule d'un titre au suivant, et c'est justement
         * la séquence `ended → emptied → loadstart → canplay → playing` qu'il
         * faut voir en entier pour savoir où elle s'interrompt.
         */
        ['ended', 'emptied', 'loadstart', 'loadedmetadata', 'canplay',
         'playing', 'play', 'pause', 'waiting', 'stalled', 'suspend',
         'error', 'abort'].forEach((e) => {
            audio.addEventListener(e, () => {
                noter('événement : ' + e,
                      e === 'error' && audio.error ? 'code ' + audio.error.code : '');
            });
        });

        /* --- L'hypothèse « Android tue l'application » se mesure ici --- */
        document.addEventListener('visibilitychange',
            () => noter('page ' + document.visibilityState));
        document.addEventListener('freeze', () => noter('PAGE GELÉE par le système'));
        document.addEventListener('resume', () => noter('page dégelée'));
        window.addEventListener('pagehide', (e) => noter('pagehide', e.persisted ? 'mise en cache' : 'déchargée'));
        if (document.wasDiscarded) noter('page REJETÉE puis rechargée par le système');
        noter('trace armée', navigator.userAgent.slice(0, 80));

        if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
            navigator.mediaDevices.addEventListener('devicechange',
                () => noter('périphériques audio modifiés'));
        }
    })();

    /*
     * Instant du dernier démarrage réel de la lecture.
     *
     * Sert de repère à la garde AVRCP ci-dessous : une commande de pause
     * venue du système n'a pas le même sens selon qu'elle arrive une seconde
     * ou une minute après le début du morceau.
     */
    let instantLecture = 0;
    audio.addEventListener('play', () => { instantLecture = performance.now(); });

    audio.addEventListener('play', updatePlayBtns);
    audio.addEventListener('pause', updatePlayBtns);
    audio.addEventListener('pause', () => flusherTemps());

    // Verrouille la base de mesure après tout déplacement (manuel ou bouclage)
    audio.addEventListener('seeking', () => {
        if (audio.loop && audio.currentTime < 2 && dernierTemps > audio.duration - 2) {
            // Bouclage de fin (repeat infini) : chaque tour compte comme une nouvelle écoute
            tempsLectureTitre = 0;
            ecouteComptee = false;
        }
        dernierTemps = audio.currentTime;
    });

    audio.addEventListener('timeupdate', () => {
        if (!audio.duration) return;

        // --- Comptage des secondes réellement écoutées ---
        const delta = audio.currentTime - dernierTemps;
        if (delta > 0 && delta <= 2) {
            // Delta plausible (~4 timeupdate/s) ; un saut > 2 s = seek, ignoré
            tempsLectureTitre += delta;
            secondesAFlusher += delta;
        }
        dernierTemps = audio.currentTime;

        /*
         * Position retenue une fois par seconde, pas à chaque timeupdate :
         * l'événement tombe environ quatre fois par seconde, et écrire autant
         * dans localStorage ne sert à rien.
         */
        if (audio.currentTime - derniereSauvegarde > 1
            || derniereSauvegarde > audio.currentTime) {
            derniereSauvegarde = audio.currentTime;
            retenirPosition();
        }

        // Une écoute compte après 30 s réelles (80 % de la durée pour les titres courts)
        const seuil = audio.duration < 30 ? audio.duration * 0.8 : 30;
        if (!ecouteComptee && tempsLectureTitre >= seuil && currentTrackId) {
            ecouteComptee = true;
            fetch('actions/compter_ecoute.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `track_id=${currentTrackId}&playlist_id=${window.sourcePlaylistId ?? ''}`
            }).catch(() => {});
        }

        if (secondesAFlusher >= 30) flusherTemps();

        const pct = (audio.currentTime / audio.duration) * 100;

        /*
         * Tant que le doigt tient la barre, c'est lui qui commande : laisser
         * timeupdate repositionner le remplissage le ferait revenir en
         * arrière entre deux mouvements, et la poignée semblerait résister.
         */
        if (!enDeplacement) {
            const miniBar = document.querySelector('#retract .player-progress_current');
            if (miniBar) miniBar.style.width = pct + '%';

            const expBar = document.querySelector('#extend .player-progress_current');
            if (expBar) expBar.style.width = pct + '%';

            majOnde(pct);
        }

        document.querySelector('.time-current').textContent = formatTime(audio.currentTime);
        document.querySelector('.time-total').textContent = formatTime(audio.duration);

        /*
         * Barre de progression du système, rafraîchie au plus une fois par
         * seconde : « timeupdate » se déclenche environ quatre fois par
         * seconde, et le système extrapole de lui-même entre deux annonces.
         */
        if (audio.currentTime - dernierePositionPubliee >= 1
            || audio.currentTime < dernierePositionPubliee) {
            dernierePositionPubliee = audio.currentTime;
            majPositionMedia();
        }
    });

    function formatTime(s) {
        if (isNaN(s)) return '0:00';
        const m = Math.floor(s / 60);
        const sec = Math.floor(s % 60).toString().padStart(2, '0');
        return `${m}:${sec}`;
    }

    /* =====================================================================
     * Intégration au système : notification Android, écran de verrouillage,
     * boutons des écouteurs Bluetooth et de la voiture.
     *
     * Sans ça, Android n'a que le titre de l'onglet et un bouton pause : pas
     * de pochette, pas de « suivant », pas de barre de progression. Trois
     * choses sont nécessaires, et il faut les trois :
     *
     *   1. metadata          — ce qui s'affiche (titre, artiste, pochette) ;
     *   2. setActionHandler  — les boutons proposés. Un bouton n'apparaît QUE
     *                          si son gestionnaire est déclaré ;
     *   3. setPositionState  — la barre de progression déplaçable. C'est elle
     *                          qui manque le plus souvent, et sans elle
     *                          l'utilisateur ne peut pas se déplacer dans le
     *                          morceau depuis l'écran verrouillé.
     *
     * L'API n'existe qu'en contexte sécurisé : en HTTPS (unison.pi5.ovh) ou
     * sur localhost. En HTTP simple sur une IP locale, rien de tout ceci ne
     * fonctionnera — c'est une limite du navigateur, pas du code.
     * ================================================================== */

    const mediaSessionDispo = 'mediaSession' in navigator;

    /*
     * Traces de ce qui a RÉELLEMENT été accepté par le navigateur.
     *
     * On ne peut pas relire un gestionnaire déjà posé (setActionHandler n'a pas
     * de lecteur), ni savoir si Android a retenu la position. Ces trois
     * variables enregistrent donc ce qui s'est passé, pour que les réglages
     * puissent l'afficher : sur un téléphone, il n'y a pas de console où aller
     * vérifier, et sans mesure on en est réduit aux suppositions.
     */
    const actionsMediaPosees = [];
    let metadonneesPosees = null;
    let positionPosee = null;

    /**
     * Publie le titre, l'artiste et la pochette auprès du système.
     *
     * L'URL de la pochette est rendue absolue : Android va la chercher
     * lui-même, hors du contexte de la page, et une URL relative n'aurait
     * alors aucun sens. Deux tailles sont déclarées pour la même image afin
     * que le système prenne la plus adaptée à l'endroit où il l'affiche
     * (notification déroulée, écran verrouillé).
     */
    function majMetadonneesMedia(track) {
        if (!mediaSessionDispo || !track) return;

        let pochette = [];
        if (track.img) {
            try {
                const url = new URL(track.img, location.href).href;
                pochette = [
                    { src: url, sizes: '256x256' },
                    { src: url, sizes: '512x512' },
                ];
            } catch (e) {
                // Une image inexploitable ne doit pas priver de notification.
            }
        }

        try {
            navigator.mediaSession.metadata = new MediaMetadata({
                title:  track.title  || 'Titre inconnu',
                artist: track.artist || 'Artiste inconnu',
                album:  'Unison',
                artwork: pochette,
            });

            metadonneesPosees = {
                titre: track.title,
                artiste: track.artist,
                pochette: pochette.length > 0,
            };
        } catch (e) {
            console.warn('MediaSession : métadonnées refusées', e);
        }
    }

    /**
     * Publie la position dans le morceau — c'est ce qui dessine la barre
     * déplaçable de la notification.
     *
     * Le système extrapole ensuite tout seul à partir de la position et de la
     * vitesse : inutile de l'appeler à chaque « timeupdate », une fois par
     * seconde suffit largement (voir l'appel dans le gestionnaire).
     *
     * setPositionState lève une exception si les valeurs sont incohérentes
     * (durée inconnue, position au-delà de la fin) — ce qui arrive
     * normalement entre deux pistes, d'où les gardes et le try.
     */
    function majPositionMedia() {
        if (!mediaSessionDispo || !navigator.mediaSession.setPositionState) return;

        const duree = audio.duration;
        if (!Number.isFinite(duree) || duree <= 0) return;

        try {
            navigator.mediaSession.setPositionState({
                duration: duree,
                playbackRate: audio.playbackRate || 1,
                position: Math.min(Math.max(audio.currentTime, 0), duree),
            });

            positionPosee = duree;
        } catch (e) {
            // Position transitoirement incohérente : le prochain appel corrigera.
        }
    }

    /** Déplacement borné dans le morceau, quelle que soit l'origine. */
    function deplacerA(secondes) {
        if (!Number.isFinite(audio.duration) || audio.duration <= 0) return;
        audio.currentTime = Math.min(Math.max(secondes, 0), audio.duration);
    }

    /**
     * Déclare les boutons proposés par le système.
     *
     * Chaque déclaration est protégée : un navigateur qui ne connaît pas une
     * action lève une TypeError, et une seule action non gérée ne doit pas
     * faire tomber toutes les autres.
     */
    function brancherMediaSession() {
        if (!mediaSessionDispo) return;

        /*
         * Chaque commande du système est tracée à son entrée.
         *
         * C'est le point décisif pour un casque Bluetooth : l'AVRCP peut
         * envoyer une commande « pause » de son propre chef, notamment juste
         * après le début d'un flux. Vue du code, cette pause est
         * indiscernable d'un appui de l'utilisateur — sauf ici, au moment où
         * elle franchit la frontière.
         */
        const tracer = (nom, fn) => (...args) => {
            if (window.__traceAudio) window.__traceAudio('commande système : ' + nom);
            return fn(...args);
        };

        /*
         * Fenêtre pendant laquelle une pause venue du système est refusée.
         *
         * Un casque Bluetooth envoie, via AVRCP, une commande « pause » de son
         * propre chef juste après l'ouverture du flux — vraisemblablement pour
         * réaligner son état interne. Vue du code, elle est indiscernable d'un
         * appui de l'utilisateur, et l'application l'exécutait : le morceau
         * s'arrêtait net dès qu'il commençait, uniquement casque connecté.
         *
         * Tracé sur l'appareil concerné : les trois commandes arrivaient à la
         * même position au dixième de seconde près. Une seconde suffit donc
         * largement à les écarter, sans gêner une vraie pause — personne
         * n'appuie sur pause dans la seconde qui suit son propre lancement,
         * et si cela arrive, un second appui fonctionne.
         */
        const FENETRE_AVRCP = 1000;

        const actions = {
            play:  tracer('play',  () => demanderLecture()),
            pause: tracer('pause', () => {
                if (!audio.paused && performance.now() - instantLecture < FENETRE_AVRCP) {
                    if (window.__traceAudio) {
                        window.__traceAudio('pause système ignorée (trop tôt après le démarrage)');
                    }
                    return;
                }
                audio.pause();
            }),

            nexttrack: () => pisteSuivante(),

            /*
             * Convention des lecteurs de musique, reprise ici : passé les
             * trois premières secondes, « précédent » revient au début du
             * morceau en cours. C'est ce que fait un appui sur « précédent »
             * dans une voiture, et s'en écarter surprend.
             */
            previoustrack: () => {
                if (audio.currentTime > 3) {
                    deplacerA(0);
                } else {
                    pistePrecedente();
                }
            },

            seekbackward: (details) => deplacerA(audio.currentTime - (details.seekOffset || 10)),
            seekforward:  (details) => deplacerA(audio.currentTime + (details.seekOffset || 10)),

            // Déplacement à un point précis : c'est le glissement du doigt sur
            // la barre de la notification.
            seekto: (details) => {
                if (details.fastSeek && typeof audio.fastSeek === 'function') {
                    audio.fastSeek(details.seekTime);
                    return;
                }
                deplacerA(details.seekTime);
            },
        };

        actionsMediaPosees.length = 0;

        for (const [nom, gestionnaire] of Object.entries(actions)) {
            try {
                navigator.mediaSession.setActionHandler(nom, gestionnaire);
                actionsMediaPosees.push(nom);
            } catch (e) {
                // Action inconnue de ce navigateur : les autres restent posées.
            }
        }
    }

    /*
     * L'état de lecture est publié séparément des métadonnées : c'est lui qui
     * décide de l'icône lecture/pause dans la notification, et il doit suivre
     * l'audio même quand la lecture est commandée depuis la page.
     */
    audio.addEventListener('play', () => {
        if (mediaSessionDispo) navigator.mediaSession.playbackState = 'playing';

        /*
         * Les actions sont redéclarées ICI, et pas seulement au chargement de
         * la page.
         *
         * Au chargement, aucune lecture n'a commencé : Android n'a pas encore
         * de session média, et Chrome n'a donc rien à qui transmettre les
         * boutons. Résultat observé sur le téléphone : la notification portait
         * bien la pochette, le titre, l'artiste et la barre de progression —
         * tout ce qui est publié APRÈS le démarrage — mais un seul bouton,
         * « pause », le seul qu'Android déduit de lui-même de l'état de
         * lecture. Les déclarer une fois la lecture lancée les fait exister
         * pour le système.
         *
         * setActionHandler est idempotent : redéclarer écrase sans effet de
         * bord, l'appel peut donc se répéter à chaque piste sans précaution.
         */
        brancherMediaSession();
        majPositionMedia();
    });

    audio.addEventListener('pause', () => {
        if (mediaSessionDispo) navigator.mediaSession.playbackState = 'paused';
        majPositionMedia();
    });

    // La durée n'est connue qu'une fois les métadonnées chargées : c'est le
    // premier moment où la barre de progression peut être publiée.
    audio.addEventListener('loadedmetadata', majPositionMedia);
    audio.addEventListener('durationchange', majPositionMedia);
    audio.addEventListener('seeked', majPositionMedia);
    audio.addEventListener('ratechange', majPositionMedia);

    brancherMediaSession();

    /*
     * Déplacement dans le morceau : la position suit le doigt ou le curseur.
     *
     * C'était un simple `click` : on sautait d'un point à un autre, et tenir
     * la barre ne faisait rien. Ici le remplissage, le temps affiche et la
     * lecture suivent le pointeur tant qu'il est tenu.
     *
     * Trois choix qui demandent un mot :
     *
     *   - setPointerCapture : le glissement continue meme quand le doigt sort
     *     de la barre, ce qui arrive constamment sur un élément de 6 px de
     *     haut. Sans lui, le déplacement s'interrompt dès qu'on dérive ;
     *   - l'affichage est mis à jour à chaque mouvement, mais le seek est
     *     limité à un toutes les 120 ms : chaque changement de currentTime
     *     relance une requête Range sur le serveur, et les enchaîner rend le
     *     son haché. L'oreille suit quand même le geste ;
     *   - un dernier seek au relâchement, pour atterrir exactement où le
     *     doigt s'est arrêté et non à la dernière position limitée.
     */
    let enDeplacement = false;
    let dernierSeek = 0;

    document.querySelectorAll('.player-progress_bar').forEach(bar => {
        const ratioDe = (clientX) => {
            const r = bar.getBoundingClientRect();
            return Math.min(Math.max((clientX - r.left) / r.width, 0), 1);
        };

        const peindre = (ratio) => {
            const remplissage = bar.querySelector('.player-progress_current');
            if (remplissage) remplissage.style.width = (ratio * 100) + '%';
            if (audio.duration) {
                const t = document.querySelector('.time-current');
                if (t) t.textContent = formatTime(ratio * audio.duration);
                majOnde(ratio * 100);
            }
        };

        bar.addEventListener('pointerdown', (e) => {
            if (!audio.duration) return;
            e.preventDefault();
            enDeplacement = true;
            bar.setPointerCapture(e.pointerId);

            const ratio = ratioDe(e.clientX);
            peindre(ratio);
            audio.currentTime = ratio * audio.duration;
            dernierSeek = performance.now();
        });

        bar.addEventListener('pointermove', (e) => {
            if (!enDeplacement || !audio.duration) return;

            const ratio = ratioDe(e.clientX);
            peindre(ratio);

            const maintenant = performance.now();
            if (maintenant - dernierSeek > 120) {
                audio.currentTime = ratio * audio.duration;
                dernierSeek = maintenant;
            }
        });

        const terminer = (e) => {
            if (!enDeplacement) return;
            enDeplacement = false;
            if (bar.hasPointerCapture(e.pointerId)) bar.releasePointerCapture(e.pointerId);
            if (audio.duration) audio.currentTime = ratioDe(e.clientX) * audio.duration;
        };

        bar.addEventListener('pointerup', terminer);
        // pointercancel : le navigateur reprend la main (appel entrant, geste
        // système). Sans ce filet, la barre resterait figée sous le doigt.
        bar.addEventListener('pointercancel', terminer);
    });

    /*
     * Navigation dans la file d'attente.
     *
     * Extraites en fonctions parce qu'elles ont maintenant trois appelants :
     * les boutons du lecteur, la fin de piste, et les commandes du système
     * (notification Android, écouteurs Bluetooth). Les trois doivent se
     * comporter exactement pareil.
     *
     * @return {boolean} false s'il n'y a rien avant / après.
     */
    function pisteSuivante() {
        if (!window.waitPlaylist || window.currentIndex >= window.waitPlaylist.length - 1) {
            return false;
        }

        window.currentIndex++;
        loadTrack(window.waitPlaylist[window.currentIndex].id);
        updateSelected();
        return true;
    }

    function pistePrecedente() {
        if (!window.waitPlaylist || window.currentIndex <= 0) {
            return false;
        }

        window.currentIndex--;
        loadTrack(window.waitPlaylist[window.currentIndex].id);
        updateSelected();
        return true;
    }

    document.querySelectorAll('.next-button').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            pisteSuivante();
        });
    });

    document.querySelectorAll('.prev-button').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            pistePrecedente();
        });
    });


    /* ---------- Reprise après un échec de chargement ---------- */

    /*
     * Mesuré le 30/09/2026, téléphone en arrière-plan, écran éteint :
     *
     *     ended → titre suivant pris en CACHE → loadstart
     *     stalled (3 s sans le moindre octet)
     *     error code 4 (MEDIA_ERR_SRC_NOT_SUPPORTED)
     *
     * Le chaînage, le préchargement et le cache avaient tous fonctionné. Ce
     * qui échouait, c'était la requête du flux lui-même. Et le lecteur ne
     * possédait aucun gestionnaire `error` : il restait muet pour de bon,
     * alors que le fichier était parfaitement valide — le morceau précédent
     * venait d'être servi depuis le même dossier par le même script.
     *
     * Un échec de chargement n'est donc pas une raison d'abandonner la file.
     * Trois tentatives espacées, puis on passe au titre suivant plutôt que de
     * laisser le silence s'installer.
     *
     * Les délais valent au moins une seconde : en dessous, les navigateurs
     * mobiles rabotent les minuteurs des pages en arrière-plan, et une
     * reprise programmée trop court partirait de toute façon en retard.
     */
    const REPRISE_DELAIS = [1000, 3000, 8000];
    const ABANDONS_MAX = 3;

    /*
     * Diagnostic, actif seulement sous trace : la requête média a échoué,
     * mais était-ce le réseau ou le serveur ? Une requête Range de deux
     * octets sur la même URL répond sans ambiguïté — et elle emprunte le même
     * chemin que le lecteur, puisque le service worker laisse passer les
     * requêtes Range.
     */
    function sonderReseau(url) {
        if (!window.__traceAudio || !url) return;
        const t0 = performance.now();
        const depuis = () => Math.round(performance.now() - t0) + ' ms, onLine=' + navigator.onLine;
        fetch(url, { headers: { Range: 'bytes=0-1' }, cache: 'no-store' })
            .then(r => window.__traceAudio('sonde réseau : HTTP ' + r.status, depuis()))
            .catch(e => window.__traceAudio('sonde réseau : ÉCHEC', e.name + ' après ' + depuis()));
    }

    audio.addEventListener('error', () => {
        /* `load()` sur un élément vidé déclenche un error sans source : rien à reprendre. */
        if (!audio.currentSrc && !audio.src) return;

        /*
         * Une seule série de reprises par chargement voulu.
         *
         * Sans ce garde-fou le gestionnaire se rappelle lui-même : mesuré en
         * essai, un élément déjà en échec refait surface avec un nouvel
         * `error` après l'abandon, et le cycle repartait indéfiniment. Le
         * drapeau ne retombe que dans `reinitialiserMesures()`, c'est-à-dire
         * quand quelqu'un demande vraiment un titre.
         */
        if (lectureAbandonnee) return;

        const idFautif = currentTrackId;
        const urlFautive = audio.currentSrc || audio.src;
        const code = audio.error ? audio.error.code : 0;
        sonderReseau(urlFautive);

        if (reprisesFaites >= REPRISE_DELAIS.length) {
            if (window.__traceAudio) {
                window.__traceAudio('titre ' + idFautif + ' abandonné',
                                    reprisesFaites + ' tentatives, code ' + code);
            }
            reprisesFaites = 0;
            abandonsConsecutifs += 1;

            /*
             * Borne volontaire. Passer au suivant est bon quand un seul
             * fichier est en cause ; quand c'est le réseau qui manque, chaque
             * titre échoue à son tour et la file entière défilerait à raison
             * de douze secondes par morceau. On s'arrête, et on le dit.
             */
            if (abandonsConsecutifs >= ABANDONS_MAX) {
                if (window.__traceAudio) {
                    window.__traceAudio('lecture interrompue',
                                        abandonsConsecutifs + ' titres illisibles de suite');
                }
                abandonsConsecutifs = 0;
                lectureAbandonnee = true;
                updatePlayBtns();
                if (window.showToast) {
                    window.showToast('Lecture interrompue : le serveur est injoignable.',
                                     'error', 6000);
                }
                return;
            }

            if (!pisteSuivante()) {
                lectureAbandonnee = true;
                updatePlayBtns();
            }
            return;
        }

        const delai = REPRISE_DELAIS[reprisesFaites];
        reprisesFaites += 1;
        if (window.__traceAudio) {
            window.__traceAudio('reprise ' + reprisesFaites + ' dans ' + delai + ' ms',
                                'code ' + code);
        }

        clearTimeout(minuteurReprise);
        minuteurReprise = setTimeout(() => {
            /* L'utilisateur a pu changer de titre entre-temps : ne pas le contredire. */
            if (currentTrackId !== idFautif) return;
            audio.load();
            demanderLecture();
        }, delai);
    });

    /* Un octet reçu suffit à dire que la source est bonne : le compteur repart. */
    ['loadeddata', 'playing'].forEach(e =>
        audio.addEventListener(e, () => { reprisesFaites = 0; abandonsConsecutifs = 0; }));

    // --- Fin de piste avec gestion du repeat ---
    let repeatMode = 0;
    audio.addEventListener('ended', () => {
        flusherTemps();
        if (repeatMode === 1) {
            // Rejoue la même piste une fois : la relecture compte comme une nouvelle écoute
            audio.currentTime = 0;
            demanderLecture();
            tempsLectureTitre = 0;
            ecouteComptee = false;
            dernierTemps = 0;
            repeatMode = 0;
            document.getElementById('repeat-button').textContent = 'repeat';
            document.getElementById('repeat-button').style.color = '';
        } else if (repeatMode === 2) {
            // Boucle infinie - audio.loop gère ça
            return;
        } else if (!pisteSuivante()) {
            // Fin de la file : rien à enchaîner, on remet juste les boutons.
            if (window.__traceAudio) window.__traceAudio('fin de file : rien à enchaîner');
            updatePlayBtns();
        }
    });

    /*
     * Met en évidence le morceau en cours dans toutes les listes affichées.
     *
     * La correspondance se fait sur data-track-id, et non plus sur le rang de
     * l'élément dans le document : `.mini-song` désigne les lignes de TOUTES
     * les sections de la page (file, favoris, historique, tous les titres),
     * si bien que « la n-ième ligne du document » désignait un morceau
     * arbitraire — et la page sautait dessus à chaque changement de piste.
     */
    function updateSelected() {
        const idCourant = String(currentTrackId ?? '');

        document.querySelectorAll('.mini-song[data-track-id]').forEach(el => {
            el.classList.toggle('selected', el.dataset.trackId === idCourant);
        });

        getFavorite(currentTrackId);

        /*
         * Le défilement automatique ne vise que la file d'attente : c'est la
         * seule liste où suivre la lecture a du sens. L'appliquer partout
         * arrachait la page sous le doigt pendant qu'on parcourait la
         * bibliothèque.
         */
        const dansFile = document.querySelector('#queue-bar .mini-song.selected');
        if (dansFile) {
            dansFile.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    document.querySelectorAll('.favorite-button').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();

            const trackId = currentTrackId;
            if (!trackId) return;
            try {
                const res = await fetch('actions/toggle_favorite.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `track_id=${trackId}`,
                });
                const data = await res.json();
                if (data.success) {
                    const active = data.liked;
                    document.querySelectorAll('.favorite-button').forEach(b => {
                        b.classList.toggle('active', active);
                        b.style.color = active ? '#C8593A' : '';
                        b.style.fontVariationSettings = active ? "'FILL' 1" : "'FILL' 0";
                    });

                    // Recharge la page si on est sur la bibliothèque
                    const mainContent = document.querySelector('#main-content');
                    if (mainContent && mainContent.innerHTML.includes('favorite-bar')) {
                        setTimeout(() => location.reload(), 500);
                    }
                }
                if (data.message) {
                    window.showToast(data.message, data.success ? 'success' : 'error');
                }
            } catch {
                window.showToast('Erreur réseau', 'error');
            }
        });
    });

    // ========== BOUTONS DU PLAYER ==========

    // --- SHUFFLE - Mélanger la queue ---
    document.getElementById('rand-button').addEventListener('click', (e) => {
        e.stopPropagation();
        const btn = e.currentTarget;

        if (!window.waitPlaylist || window.waitPlaylist.length < 2) {
            window.showToast('Queue trop courte pour mélanger', 'error');
            return;
        }

        const current = window.waitPlaylist[0];
        const rest = window.waitPlaylist.slice(1);

        for (let i = rest.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [rest[i], rest[j]] = [rest[j], rest[i]];
        }

        window.waitPlaylist = [current, ...rest];

        // Met à jour le DOM de la queue
        const queueBody = document.querySelector('#queue-bar .body-bar');
        if (queueBody) {
            window.remplirLignesTitres(queueBody, window.waitPlaylist, {
                file: true,
                badge: true,
            });
        }

        btn.classList.add('active');
        btn.style.color = '#C8593A';
        window.showToast('Queue mélangée! 🔀');
    });

    // --- REPEAT - 3 modes: repeat → repeat_one → all_inclusive ---
    document.getElementById('repeat-button').addEventListener('click', (e) => {
        e.stopPropagation();
        const btn = e.currentTarget;

        repeatMode = (repeatMode + 1) % 3;

        const displays = ['repeat', 'repeat_one', 'all_inclusive'];
        const colors = ['', '#C8593A', '#C8593A'];

        btn.textContent = displays[repeatMode];
        btn.style.color = colors[repeatMode];
        btn.classList.toggle('active', repeatMode > 0);

        audio.loop = (repeatMode === 2);

    });

    // --- VOLUME - 3 niveaux: 0% → 50% → 100% ---
    let volumeLevel = 2;
    document.getElementById('volume-button').addEventListener('click', (e) => {
        e.stopPropagation();
        const btn = e.currentTarget;

        volumeLevel = (volumeLevel + 1) % 3;
        const volumes = [0, 0.5, 1];
        const icons = ['volume_off', 'volume_down', 'volume_up'];

        audio.volume = volumes[volumeLevel];
        btn.textContent = icons[volumeLevel];
    });

    /*
     * ouvrirModale() vient de scripts/actionsTitre.js, chargé juste avant ce
     * fichier. La fabrique y a été remontée parce que trois appelants en ont
     * besoin, et non le lecteur seul.
     */
    const ouvrirModale = window.ouvrirModale;

    // --- ADD - Ajouter à une playlist ---
    /*
     * Le lecteur ouvrait sa propre liste de boutons : un clic ajoutait, sans
     * montrer dans quelles playlists le titre se trouvait déjà et sans moyen
     * de l'en retirer. Il passe sur le sélecteur à cases à cocher, le même
     * que le menu « … » d'une ligne et que la page d'un titre.
     */
    document.getElementById('add-button').addEventListener('click', (e) => {
        e.stopPropagation();
        window.ouvrirAjoutPlaylist(currentTrackId, titreCourant);
    });

    /*
     * Le « … » en haut du lecteur déployé n'avait aucun gestionnaire : il se
     * dessinait, se survolait, et ne faisait rien. Il porte désormais les
     * actions du titre en cours — les mêmes que le menu d'une ligne de liste,
     * pour qu'un même dessin veuille dire la même chose partout.
     */
    document.getElementById('more-button').addEventListener('click', (e) => {
        e.stopPropagation();

        if (!currentTrackId) {
            window.showToast('Aucun titre en cours', 'error');
            return;
        }

        const { contenu, fermer } = ouvrirModale('Options du titre');

        if (titreCourant) {
            const sous = document.createElement('div');
            sous.className = 'ajout-pl-titre';
            sous.textContent = titreCourant;
            contenu.appendChild(sous);
        }

        const actions = [
            {
                icone: 'playlist_add',
                libelle: 'Ajouter à une playlist',
                faire: () => window.ouvrirAjoutPlaylist(currentTrackId, titreCourant),
            },
            {
                icone: 'playlist_play',
                libelle: "Ajouter à la liste d'attente",
                faire: () => window.fileAjouter(currentTrackId, 'fin'),
            },
            {
                icone: 'low_priority',
                libelle: 'Écouter juste après',
                faire: () => window.fileAjouter(currentTrackId, 'suivant'),
            },
            {
                icone: 'info',
                libelle: 'Voir le titre',
                faire: () => {
                    sessionStorage.setItem('titre_id', currentTrackId);
                    navigateTo('library/titre');
                },
            },
        ];

        // Pas de destinataire, pas d'entrée : un compte hors foyer n'a
        // personne à qui envoyer.
        const partenaire = window.UNISON_PARTENAIRE;
        if (partenaire && partenaire.nom) {
            actions.push({
                icone: 'send',
                libelle: 'Envoyer à ' + partenaire.nom,
                faire: () => envoyerTitreCourant(),
            });
        }

        /*
         * Pas de « Paramètres audio » ici : ce menu porte des gestes sur le
         * titre, et le son a déjà son propre bouton dans la barre du bas du
         * lecteur. Les deux au même endroit brouillaient ce que le « … »
         * voulait dire.
         */

        actions.forEach((a) => {
            const choix = document.createElement('button');
            choix.type = 'button';
            choix.className = 'modale-choix';

            const icone = document.createElement('span');
            icone.className = 'material-symbols-outlined';
            icone.textContent = a.icone;

            choix.append(icone, document.createTextNode(a.libelle));
            choix.addEventListener('click', () => {
                fermer();
                a.faire();
            });
            contenu.appendChild(choix);
        });
    });

    /** Partage du titre en cours, sans texte : le geste « tiens, écoute ça ». */
    async function envoyerTitreCourant() {
        try {
            const res = await fetch('actions/messages_envoyer.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ track_id: String(currentTrackId) }),
            });
            const data = await res.json();
            window.showToast(
                data.success ? 'Envoyé à ' + window.UNISON_PARTENAIRE.nom
                             : (data.message || 'Envoi impossible'),
                data.success ? 'success' : 'error'
            );
        } catch (e) {
            window.showToast('Erreur réseau', 'error');
        }
    }

    // --- MORE - Menu d'actions supplémentaires ---
    async function showSettingsModal() {
        const { modale, contenu } = ouvrirModale('Paramètres audio');

        contenu.insertAdjacentHTML('beforeend', `
            <div class="modale-champ">
                <label class="modale-label" for="volume-slider">Volume : <span id="volume-value">100</span>%</label>
                <input type="range" id="volume-slider" min="0" max="100" value="100">
            </div>
            <div class="modale-champ">
                <label class="modale-label" for="audio-device-select">Sortie audio</label>
                <select id="audio-device-select">
                    <option value="">Détection en cours...</option>
                </select>
            </div>
            <div class="modale-champ">
                <span class="modale-label">Notifications système</span>
                <div id="etat-notification" class="modale-etat"></div>
            </div>
            <button type="button" id="close-settings" class="modale-fermer">Fermer</button>
        `);

        /*
         * Diagnostic MESURÉ, et non déduit.
         *
         * « Seul le bouton lecture apparaît » a deux causes très différentes :
         * soit le navigateur refuse l'API (page non sécurisée), soit il
         * l'accepte et c'est Android qui choisit de ne pas afficher les
         * boutons. On ne peut pas ouvrir de console sur un téléphone : cet
         * encart montre donc ce qui a été réellement accepté, et permet de
         * trancher en un coup d'œil.
         */
        const etatNotif = contenu.querySelector('#etat-notification');
        const lignes = [];

        if (!mediaSessionDispo) {
            lignes.push('<span class="modale-etat-alerte">API indisponible.</span> '
                + "Cette page n'est pas en contexte sécurisé : le navigateur "
                + "désactive l'intégration au système. Ouvrez Unison en "
                + '<b>HTTPS</b> (https://unison.pi5.ovh) plutôt qu\'en http:// '
                + 'sur une adresse IP locale.');
        } else {
            const sur = window.isSecureContext !== false;
            lignes.push(sur
                ? '<span class="modale-etat-actif">API active.</span>'
                : '<span class="modale-etat-alerte">Contexte non sécurisé.</span> '
                  + 'Passez en HTTPS.');

            lignes.push('Boutons acceptés : <b>'
                + (actionsMediaPosees.length ? actionsMediaPosees.join(', ') : 'aucun')
                + '</b>');

            lignes.push('Métadonnées : <b>'
                + (metadonneesPosees
                    ? metadonneesPosees.titre + ' — ' + metadonneesPosees.artiste
                      + (metadonneesPosees.pochette ? ' (avec pochette)' : ' (sans pochette)')
                    : 'aucune publiée')
                + '</b>');

            lignes.push('Barre de progression : <b>'
                + (positionPosee
                    ? 'durée ' + Math.round(positionPosee) + ' s publiée'
                    : 'jamais publiée — durée du morceau inconnue')
                + '</b>');

            /*
             * Si tout est publié et que les boutons manquent quand même, la
             * décision vient d'Android, pas d'Unison : la notification réduite
             * cache les commandes tant qu'elle n'est pas dépliée.
             */
            if (actionsMediaPosees.includes('nexttrack') && metadonneesPosees) {
                lignes.push('<span class="modale-etat-actif">Tout est publié.</span> '
                    + 'Si « précédent / suivant » manquent, dépliez la notification '
                    + '(glissez vers le bas dessus) : Android n\'affiche que deux '
                    + 'commandes tant qu\'elle est repliée.');
            }
        }

        etatNotif.innerHTML = lignes.join('<br>');

        const slider = contenu.querySelector('#volume-slider');
        const volumeValue = contenu.querySelector('#volume-value');
        const deviceSelect = contenu.querySelector('#audio-device-select');
        const closeBtn = contenu.querySelector('#close-settings');

        // Initialise le slider avec le volume actuel
        slider.value = Math.round(audio.volume * 100);
        volumeValue.textContent = slider.value;

        // Mise à jour du volume
        slider.oninput = () => {
            audio.volume = slider.value / 100;
            volumeValue.textContent = slider.value;
        };

        // Fonction pour énumérer les appareils
        async function enumerateAudioDevices() {
            /*
             * Le choix de la sortie audio depuis une page web repose sur
             * setSinkId(), qui n'existe que sur les navigateurs de bureau.
             * Android ne l'implémente pas et n'expose aucun appareil de type
             * « audiooutput » : c'est le système qui décide où sort le son
             * (haut-parleur, casque, Bluetooth), et il le fait très bien.
             *
             * On le dit clairement plutôt que d'afficher « Aucun appareil
             * détecté », qui laisse croire à une panne.
             */
            if (typeof audio.setSinkId !== 'function') {
                deviceSelect.innerHTML =
                    '<option>Géré par le système sur cet appareil</option>';
                deviceSelect.disabled = true;

                const aide = document.createElement('small');
                aide.className = 'modale-aide';
                aide.textContent = "Sur mobile, la sortie audio se choisit dans Android "
                                 + "(casque, Bluetooth) : le navigateur n'a pas la main dessus.";
                deviceSelect.insertAdjacentElement('afterend', aide);
                return;
            }

            /*
             * Les libellés des appareils restent vides tant qu'aucune
             * permission média n'a été accordée. On la demande ici, à
             * l'ouverture des réglages — et non au chargement de la page, où
             * elle réclamait le micro à chaque visite pour une fonction que
             * l'utilisateur n'allait peut-être jamais ouvrir.
             */
            try {
                const flux = await navigator.mediaDevices.getUserMedia({ audio: true });
                flux.getTracks().forEach(piste => piste.stop());
            } catch (err) {
                // Permission refusée : on énumère quand même, sans les noms.
            }

            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                const audioDevices = devices.filter(device => device.kind === 'audiooutput');

                if (audioDevices.length > 0) {
                    deviceSelect.innerHTML = '';
                    audioDevices.forEach(device => {
                        const option = document.createElement('option');
                        option.value = device.deviceId;
                        option.textContent = device.label || `Appareil audio ${device.deviceId.substring(0, 8)}`;
                        deviceSelect.appendChild(option);
                    });

                    // Pré-sélectionne l'appareil actuellement utilisé
                    deviceSelect.value = audio.sinkId || '';

                    // Si aucun sinkId, sélectionne le premier
                    if (!audio.sinkId && audioDevices.length > 0) {
                        deviceSelect.value = audioDevices[0].deviceId;
                    }
                } else {
                    deviceSelect.innerHTML = '<option>Aucun appareil détecté</option>';
                }
            } catch (err) {
                console.error('Erreur énumération appareils:', err);
                deviceSelect.innerHTML = '<option>Erreur énumération appareils</option>';
            }
        }

        // Change l'appareil audio quand on sélectionne
        deviceSelect.onchange = async () => {
            if (deviceSelect.value) {
                try {
                    if (audio.setSinkId) {
                        await audio.setSinkId(deviceSelect.value);
                        window.showToast('Appareil audio changé', 'success');
                    } else {
                        window.showToast('setSinkId non supporté', 'error');
                    }
                } catch (err) {
                    console.error('Erreur setSinkId:', err);
                    window.showToast('Erreur: ' + err.message, 'error');
                }
            }
        };

        // Énumère les appareils
        await enumerateAudioDevices();

        closeBtn.onclick = () => modale.remove();
    }

    document.getElementById('menu-button').addEventListener('click', (e) => {
        e.stopPropagation();
        showSettingsModal();
    });

    // --- QUEUE - Fermer le player et naviguer ---
    /*
     * La navigation ne dépend plus de la fin de l'animation de fermeture.
     *
     * Elle était déclenchée par un `animationend` sur #extend. Sur bureau la
     * carte du lecteur est ancrée dans la mise en page et porte
     * `animation: none !important` (elle n'a ni ouverture ni fermeture à
     * jouer) : l'événement ne survenait jamais, et le bouton ne faisait
     * strictement rien. On navigue donc tout de suite, et la fermeture n'est
     * plus qu'un effet visuel, là où elle existe.
     */
    const queueBtn = document.getElementById('queue-button');
    if (queueBtn) {
        queueBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();

            // Panneau fixe du bureau : rien à fermer, il reste à l'écran.
            if (extend.classList.contains('expanded')) {
                extend.classList.remove('expanded');
                extend.classList.add('closing');
                extend.addEventListener('animationend', () => {
                    extend.classList.remove('closing');
                    extend.style.visibility = 'hidden';
                }, { once: true });
            }

            navigateTo('player/queue');
        });
    }

    // --- Cherche si dans Favorite ---
    async function getFavorite(trackId) {
        const res = await fetch(`actions/get_favorite.php?track_id=${trackId}`);
        const text = await res.text();
        const data = JSON.parse(text);

        if (data.status) {
            const active = data.liked;
            document.querySelectorAll('.favorite-button').forEach(btn => {
                btn.classList.toggle('active', active);
                btn.style.color = active ? '#C8593A' : '';
                btn.style.fontVariationSettings = active ? "'FILL' 1" : "'FILL' 0";
            });
        }
    }

    window.getFavorite = getFavorite;
})();
