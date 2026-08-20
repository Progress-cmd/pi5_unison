(function() {
    const player = document.getElementById('player');
    const closeBtn = document.getElementById('close-button');
    const extend = document.getElementById('extend');
    let currentTrackId = null;

    // --- Suivi d'écoute ---
    let tempsLectureTitre = 0;   // secondes réelles écoutées sur le titre courant (seuil d'écoute)
    let secondesAFlusher = 0;    // secondes accumulées pas encore envoyées au serveur
    let ecouteComptee = false;   // une seule écoute comptée par chargement / tour de boucle
    let dernierTemps = 0;        // dernier currentTime vu au timeupdate
    let dernierePositionPubliee = 0; // dernière position annoncée au système (notification)
    window.sourcePlaylistId = null; // playlist d'origine de la queue (null = hors playlist)

    // --- Audio setup ---
    const audio = new Audio();

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
    async function loadTrack(id, autoplay = true) {
        flusherTemps();
        tempsLectureTitre = 0;
        ecouteComptee = false;
        dernierTemps = 0;
        dernierePositionPubliee = 0;
        currentTrackId = id;

        let track = null;
        try {
            const res = await fetch(`actions/getTrack.php?id=${id}`);
            track = res.ok ? await res.json() : null;
        } catch (e) {
            track = null;
        }

        /*
         * Titre introuvable : typiquement une file d'attente qui référence un
         * morceau supprimé depuis. On le dit — le lecteur restait auparavant
         * figé sur la piste précédente sans le moindre signe. Pas d'avance
         * automatique vers la suivante : si plusieurs titres manquent, elle
         * ferait défiler toute la file d'un coup.
         */
        if (!track || track.id === null) {
            currentTrackId = null;
            if (window.showToast) window.showToast('Ce titre est introuvable', 'error', 6000);
            return;
        }

        audio.src = track.src;

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

        audio.load();
        if (autoplay) {
            audio.addEventListener('canplay', () => {
                audio.play().catch(() => {});
            }, { once: true });
        }
        updateSelected();
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

        window.waitPlaylist = playlist;

        if (!currentTrackId) {
            loadTrack(playlist[0]['id'], false);
            window.currentIndex = 0;
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

    function updatePlayBtns() {
        const icon = audio.paused ? 'play_arrow' : 'pause';
        document.querySelectorAll('.play-button').forEach(el => {
            el.textContent = icon;
        });
    }

    document.querySelectorAll('.play-button').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            audio.paused ? audio.play() : audio.pause();
        });
    });

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

        const miniBar = document.querySelector('#retract .player-progress_current');
        if (miniBar) miniBar.style.width = pct + '%';

        const expBar = document.querySelector('#extend .player-progress_current');
        if (expBar) expBar.style.width = pct + '%';

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

        const actions = {
            play:  () => audio.play().catch(() => {}),
            pause: () => audio.pause(),

            stop: () => {
                audio.pause();
                deplacerA(0);
            },

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

    document.querySelectorAll('.player-progress_bar').forEach(bar => {
        bar.addEventListener('click', function(e) {
            if (!audio.duration) return;
            const rect = this.getBoundingClientRect();
            const ratio = (e.clientX - rect.left) / rect.width;
            audio.currentTime = ratio * audio.duration;
        });
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

    // --- Fin de piste avec gestion du repeat ---
    let repeatMode = 0;
    audio.addEventListener('ended', () => {
        flusherTemps();
        if (repeatMode === 1) {
            // Rejoue la même piste une fois : la relecture compte comme une nouvelle écoute
            audio.currentTime = 0;
            audio.play();
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
     * Fabrique commune aux deux modales du lecteur.
     *
     * Chacune recopiait auparavant la même quinzaine de lignes de
     * style.cssText — fond, centrage, fermeture au clic hors-cadre — avec des
     * couleurs en dur qui ignoraient la palette. L'habillage est passé en CSS
     * (.modale, voir style.css), et il n'existe plus qu'un seul exemplaire de
     * la mécanique.
     */
    function ouvrirModale(titre) {
        const modale = document.createElement('div');
        modale.className = 'modale';

        const contenu = document.createElement('div');
        contenu.className = 'modale-contenu';

        const entete = document.createElement('div');
        entete.className = 'modale-titre';
        entete.textContent = titre;
        contenu.appendChild(entete);

        modale.appendChild(contenu);
        modale.addEventListener('click', (e) => {
            if (e.target === modale) modale.remove();
        });

        // Échap ferme aussi : une modale qui ne se ferme qu'au clic piège
        // l'utilisateur au clavier.
        const surTouche = (e) => {
            if (e.key === 'Escape') { modale.remove(); document.removeEventListener('keydown', surTouche); }
        };
        document.addEventListener('keydown', surTouche);

        document.body.appendChild(modale);
        return { modale, contenu };
    }

    // --- ADD - Ajouter à une playlist ---
    async function showPlaylistModal() {
        if (!currentTrackId) {
            window.showToast('Aucune chanson en cours', 'error');
            return;
        }

        try {
            const res = await fetch('actions/get_playlists.php');
            const data = await res.json();

            if (!data.success || !data.playlists.length) {
                window.showToast('Aucune playlist disponible', 'error');
                return;
            }

            const { modale, contenu } = ouvrirModale('Ajouter à une playlist');

            data.playlists.forEach(playlist => {
                const choix = document.createElement('button');
                choix.type = 'button';
                choix.className = 'modale-choix';
                // textContent : un nom de playlist est saisi par l'utilisateur.
                choix.textContent = playlist.name;
                choix.onclick = async () => {
                    await addToPlaylist(currentTrackId, playlist.id, playlist.name);
                    modale.remove();
                };
                contenu.appendChild(choix);
            });
        } catch (e) {
            window.showToast('Erreur: ' + e.message, 'error');
        }
    }

    async function addToPlaylist(trackId, playlistId, playlistName) {
        try {
            const res = await fetch('actions/add_to_playlist.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `track_id=${trackId}&playlist_id=${playlistId}`
            });
            const data = await res.json();
            if (data.success) {
                window.showToast(`Ajouté à "${playlistName}" ✓`);
            } else {
                window.showToast('Erreur lors de l\'ajout', 'error');
            }
        } catch (e) {
            window.showToast('Erreur: ' + e.message, 'error');
        }
    }

    document.getElementById('add-button').addEventListener('click', (e) => {
        e.stopPropagation();
        showPlaylistModal();
    });

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
    const queueBtn = document.getElementById('queue-button');
    if (queueBtn) {
        queueBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();

            // Ferme le player extended
            extend.classList.remove('expanded');
            extend.classList.add('closing');
            extend.addEventListener('animationend', () => {
                extend.classList.remove('closing');
                extend.style.visibility = 'hidden';
                // Navigue après la fermeture du player
                navigateTo('player/queue');
            }, { once: true });
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
