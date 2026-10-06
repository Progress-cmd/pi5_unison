/*
 * Rendu d'une ligne de titre côté client.
 *
 * Pendant de ligneTitre() dans src/includes/rendu.php : même structure, mêmes
 * classes, même attribut data-piste. Les deux doivent évoluer ensemble.
 *
 * Rien n'est posé en innerHTML à partir des données : un titre de morceau
 * vient de YouTube, donc de l'extérieur, et les copies précédentes
 * l'interpolaient telles quelles dans un gabarit — de quoi exécuter du script
 * en important une vidéo au titre bien choisi.
 */
(function () {
    /**
     * @param {object} track  id, title, img, artists_names
     * @param {object} [opts]
     *   - sousTitre {string}  remplace le nom des artistes
     *   - classes   {string}  classes supplémentaires
     *   - badge     {boolean} pastille « EN COURS »
     *   - index     {number}  position dans la file d'attente
     *   - menu      {boolean} bouton « … » (vrai par défaut)
     * @returns {HTMLDivElement}
     */
    window.creerLigneTitre = function (track, opts = {}) {
        const ligne = document.createElement('div');
        ligne.className = ('content mini-song ' + (opts.classes || '')).trim();
        ligne.dataset.piste = '';
        ligne.dataset.trackId = track.id;
        if (opts.index !== undefined && opts.index !== null) {
            ligne.dataset.index = opts.index;
        }

        const img = document.createElement('img');
        img.className = 'song-img';
        img.alt = '';
        img.setAttribute('src', track.img ?? '');
        ligne.appendChild(img);

        const infos = document.createElement('div');
        infos.className = 'song-infos';

        const titre = document.createElement('div');
        titre.className = 'song-title';
        titre.textContent = track.title ?? '';

        const artiste = document.createElement('div');
        artiste.className = 'song-artist';
        artiste.textContent = opts.sousTitre ?? track.artists_names ?? '';

        infos.append(titre, artiste);
        ligne.appendChild(infos);

        if (opts.badge) {
            /*
             * Une vague animée plutôt que le texte « EN COURS » : elle dit la
             * même chose, sans mot à lire, et s'arrête quand la lecture
             * s'arrête — ce que le badge ne faisait pas. Les trois barres sont
             * celles de la présence du partenaire, mêmes keyframes.
             */
            const onde = document.createElement('div');
            onde.className = 'onde-lecture';
            onde.setAttribute('aria-label', 'Titre en cours de lecture');
            for (let i = 0; i < 3; i++) onde.appendChild(document.createElement('i'));
            ligne.appendChild(onde);
        }

        if (opts.menu !== false) {
            const bouton = document.createElement('button');
            bouton.className = 'buttons material-symbols-outlined';
            bouton.setAttribute('aria-label', 'Options du titre');
            bouton.textContent = 'more_vert';
            ligne.appendChild(bouton);
        }

        return ligne;
    };

    /**
     * Remplit un conteneur avec une liste de titres, ou un message si elle est vide.
     *
     * `opts.file` marque la liste comme étant la file d'attente : les lignes
     * portent alors leur position, et un clic déplace la lecture au lieu de
     * simplement lancer le morceau. Une liste d'historique ou de favoris n'est
     * pas la file — y poser un index ferait sauter la lecture n'importe où.
     */
    window.remplirLignesTitres = function (conteneur, titres, opts = {}) {
        conteneur.textContent = '';

        if (!titres || titres.length === 0) {
            const vide = document.createElement('div');
            vide.className = 'content ligne-vide';
            const em = document.createElement('em');
            em.textContent = opts.messageVide || 'Liste vide';
            vide.appendChild(em);
            conteneur.appendChild(vide);
            return;
        }

        const fragment = document.createDocumentFragment();
        titres.forEach((track, idx) => {
            fragment.appendChild(window.creerLigneTitre(track, {
                ...opts,
                index: opts.file ? idx : undefined,
                classes: ((opts.classes || '')
                    + (opts.file && idx === window.currentIndex ? ' selected' : '')).trim(),
            }));
        });
        conteneur.appendChild(fragment);

        if (window.initializeTrackContextMenus) window.initializeTrackContextMenus();
    };

    /**
     * Rend une liste de titres par paquets, au fil du défilement.
     *
     * `remplirLignesTitres()` construit tout d'un coup. Sur une file de 2000
     * titres, ça faisait 2000 sous-arbres DOM à chaque affichage de l'accueil
     * ou de la liste d'attente — mesuré à plus d'un mégaoctet de HTML pour le
     * seul fragment d'accueil — alors que l'écran en montre une quinzaine.
     *
     * Aucune requête supplémentaire : les titres sont déjà tous en mémoire
     * dans `window.waitPlaylist`. Ce qu'on étale, c'est la construction du
     * DOM, pas le chargement des données.
     *
     * La fenêtre s'étend dans les DEUX sens. C'est nécessaire à la liste
     * d'attente : si le morceau en cours est au 1500e rang, commencer au
     * premier titre obligerait à bâtir 1500 lignes invisibles avant de
     * pouvoir l'amener sous les yeux — exactement ce qu'on cherche à éviter.
     * On part donc de lui, et on remonte si l'utilisateur remonte.
     *
     * @param {HTMLElement} conteneur
     * @param {Array}  titres
     * @param {Object} opts   les options de creerLigneTitre(), plus :
     *   - paquet  {number} lignes par paquet (40 par défaut)
     *   - deja    {number} lignes déjà rendues par le serveur, à ne pas refaire
     *   - depuis  {number} index où commencer (0 par défaut)
     *   - apresPaquet {Function} rappelé après chaque paquet : les écouteurs
     *                      posés sur les lignes doivent être rejoués.
     * @return {{tout: Function}} tout() force le rendu complet.
     */
    window.rendreParPaquets = function (conteneur, titres, opts = {}) {
        const PAQUET = opts.paquet || 40;
        const apresPaquet = opts.apresPaquet || function () {};

        if (!titres || titres.length === 0) {
            window.remplirLignesTitres(conteneur, titres, opts);
            return { tout: function () {} };
        }

        const borne = (n) => Math.min(Math.max(n, 0), titres.length);

        /* premier = premier index rendu ; rendus = un cran après le dernier. */
        let premier = borne(opts.depuis || 0);
        let rendus  = Math.max(premier, borne(opts.deja || 0));

        // Le serveur n'a rien posé : on repart d'un conteneur vide.
        if (!opts.deja) conteneur.textContent = '';

        function ligne(i) {
            return window.creerLigneTitre(titres[i], {
                ...opts,
                index: opts.file ? i : undefined,
                classes: ((opts.classes || '')
                    + (opts.file && i === window.currentIndex ? ' selected' : '')).trim(),
            });
        }

        function apres() {
            if (window.initializeTrackContextMenus) window.initializeTrackContextMenus();
            apresPaquet(premier, rendus);
        }

        /*
         * Sentinelles plutôt qu'un écouteur de défilement : elles ne coûtent
         * rien tant qu'elles restent hors du cadre. Même choix que la page
         * d'activité.
         */
        const hautSentinelle = document.createElement('div');
        hautSentinelle.className = 'rendu-sentinelle';
        hautSentinelle.setAttribute('aria-hidden', 'true');

        const basSentinelle = document.createElement('div');
        basSentinelle.className = 'rendu-sentinelle';
        basSentinelle.setAttribute('aria-hidden', 'true');

        conteneur.insertBefore(hautSentinelle, conteneur.firstChild);
        conteneur.appendChild(basSentinelle);

        let obsHaut = null;
        let obsBas = null;

        function etendreVersLeBas(cible) {
            if (rendus >= titres.length) return;
            const fin = borne(cible);
            const frag = document.createDocumentFragment();
            for (let i = rendus; i < fin; i++) frag.appendChild(ligne(i));

            // Avant la sentinelle : elle doit rester la dernière, sinon elle
            // reste visible et l'observateur se redéclenche sans fin.
            conteneur.insertBefore(frag, basSentinelle);
            rendus = fin;
            apres();

            if (rendus >= titres.length && obsBas) {
                obsBas.disconnect();
                basSentinelle.remove();
            }
        }

        function etendreVersLeHaut(cible) {
            if (premier <= 0) return;
            const debut = borne(cible);
            const frag = document.createDocumentFragment();
            for (let i = debut; i < premier; i++) frag.appendChild(ligne(i));

            /*
             * Préserver la position de lecture : insérer au-dessus décale
             * tout ce qui est visible vers le bas, et l'utilisateur perdrait
             * sa ligne des yeux à chaque paquet remonté.
             */
            const avant = conteneur.scrollHeight;
            conteneur.insertBefore(frag, hautSentinelle.nextSibling);
            conteneur.scrollTop += conteneur.scrollHeight - avant;

            premier = debut;
            apres();

            if (premier <= 0 && obsHaut) {
                obsHaut.disconnect();
                hautSentinelle.remove();
            }
        }

        etendreVersLeBas(rendus + PAQUET);

        if (rendus < titres.length) {
            obsBas = new IntersectionObserver((e) => {
                if (e.some(x => x.isIntersecting)) etendreVersLeBas(rendus + PAQUET);
            }, { rootMargin: '400px' });
            obsBas.observe(basSentinelle);
        } else {
            basSentinelle.remove();
        }

        if (premier > 0) {
            obsHaut = new IntersectionObserver((e) => {
                if (e.some(x => x.isIntersecting)) etendreVersLeHaut(premier - PAQUET);
            }, { rootMargin: '400px' });
            obsHaut.observe(hautSentinelle);
        } else {
            hautSentinelle.remove();
        }

        return {
            tout: () => { etendreVersLeHaut(0); etendreVersLeBas(titres.length); },
        };
    };
})();
