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
            const badge = document.createElement('div');
            badge.className = 'running badge';
            badge.textContent = 'EN COURS';
            ligne.appendChild(badge);
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
})();
