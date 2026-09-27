(function() {
    const input      = document.getElementById('search-entry');
    const form       = document.getElementById('search-form');
    const boutonVider= document.getElementById('search-clear');
    const filtres    = document.getElementById('search-filters');
    const resultsDiv = document.getElementById('search-results');

    if (!input) return;
    if (window._searchInit) return;
    window.addEventListener('beforeunload', () => { window._searchInit = false; }, { once: true });
    window._searchInit = true;

    const playlistId   = sessionStorage.getItem('search_playlist_id');
    const playlistName = sessionStorage.getItem('search_playlist_name');

    const CLE_RECENTES = 'search_recentes';
    const MAX_RECENTES = 6;

    // Marqueurs de surlignage posés par le serveur. Ce sont des caractères de
    // contrôle : on échappe d'abord tout le texte, puis on les remplace par des
    // balises. Une correspondance ne peut donc jamais produire de HTML.
    const HL_DEBUT = '\u0001';
    const HL_FIN   = '\u0002';

    const escape = str => String(str ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
    }[c]));

    const surligner = str =>
        escape(str)
            .split(HL_DEBUT).join('<mark>')
            .split(HL_FIN).join('</mark>');

    /** « 3:07 » pour un titre. */
    function duree(secondes) {
        const m = Math.floor(secondes / 60);
        const s = secondes % 60;
        return `${m}:${String(s).padStart(2, '0')}`;
    }

    /** « 1 h 12 » ou « 12 min » pour un cumul. */
    function dureeLongue(secondes) {
        if (secondes >= 3600) {
            const h = Math.floor(secondes / 3600);
            return `${h} h ${String(Math.floor((secondes % 3600) / 60)).padStart(2, '0')}`;
        }
        return `${Math.max(1, Math.round(secondes / 60))} min`;
    }

    /** « 3 titres » / « 1 titre » — le pluriel se voit tout de suite s'il manque. */
    const pluriel = (n, singulier, plur = singulier + 's') => `${n} ${n > 1 ? plur : singulier}`;

    const POCHETTE_DEFAUT =
        'https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop';

    // ------------------------------------------------------- bandeau playlist

    if (playlistId && !document.getElementById('playlist-context')) {
        const banner = document.createElement('div');
        banner.id = 'playlist-context';
        banner.dataset.playlistId = playlistId;
        /*
         * Le nom arrive déjà traduit (libellePlaylist côté PHP) : on affichait
         * « Ajouter à Favorite Tracks », le nom interne de la table.
         */
        banner.innerHTML = `<span class="material-symbols-outlined">playlist_add</span>
                            <span class="playlist-context-texte">Cochez les titres à ajouter à
                            <em>${escape(playlistName) || escape(playlistId)}</em></span>`;
        form.insertAdjacentElement('afterend', banner);
    }

    // ---------------------------------------------------- recherches récentes

    const lireRecentes = () => {
        try { return JSON.parse(localStorage.getItem(CLE_RECENTES)) || []; }
        catch (e) { return []; }
    };

    function memoriserRecente(terme) {
        // La plus récente en tête, sans doublon, liste bornée.
        const liste = [terme, ...lireRecentes().filter(t => t !== terme)].slice(0, MAX_RECENTES);
        try { localStorage.setItem(CLE_RECENTES, JSON.stringify(liste)); } catch (e) {}
    }

    // ------------------------------------------------------------- affichages

    function afficherAccueil() {
        filtres.hidden = true;
        const recentes = lireRecentes();

        resultsDiv.innerHTML = `
            <div class="search-vide">
                <span class="material-symbols-outlined search-vide-icone">search</span>
                <p class="search-vide-titre">Que souhaitez-vous écouter ?</p>
                <p class="search-vide-sous">Cherchez un titre, un artiste ou une playlist.</p>
                ${recentes.length ? `
                    <div class="search-recentes">
                        <div class="search-recentes-tete">
                            <span>Recherches récentes</span>
                            <button type="button" id="vider-recentes">Effacer</button>
                        </div>
                        <div class="search-recentes-liste">
                            ${recentes.map(t => `
                                <button type="button" class="search-chip" data-terme="${escape(t)}">
                                    <span class="material-symbols-outlined">history</span>${escape(t)}
                                </button>`).join('')}
                        </div>
                    </div>` : ''}
            </div>`;
    }

    function afficherChargement() {
        // Squelette : la page ne doit pas se vider entre deux frappes.
        resultsDiv.innerHTML = `
            <div class="search-squelette">
                ${Array.from({ length: 4 }, () => `
                    <div class="squelette-ligne">
                        <div class="squelette-bloc squelette-img"></div>
                        <div class="squelette-texte">
                            <div class="squelette-bloc squelette-l1"></div>
                            <div class="squelette-bloc squelette-l2"></div>
                        </div>
                    </div>`).join('')}
            </div>`;
    }

    function afficherAucunResultat(terme) {
        filtres.hidden = true;
        resultsDiv.innerHTML = `
            <div class="search-vide">
                <span class="material-symbols-outlined search-vide-icone">search_off</span>
                <p class="search-vide-titre">Aucun résultat pour « ${escape(terme)} »</p>
                <p class="search-vide-sous">Vérifiez l'orthographe, ou essayez un terme plus court.</p>
            </div>`;
    }

    // ------------------------------------------------------------- rendu

    function ligneTitre(t) {
        const badges = [...t.genres, ...t.tags]
            .slice(0, 3)
            .map(g => `<span class="search-badge">${escape(g.name)}</span>`)
            .join('');

        // .mini-song + data-track-id : le menu contextuel existant s'y accroche.
        return `
        <div class="mini-song search-item" data-track-id="${t.id}" data-titre-id="${t.id}">
            <img src="${escape(t.img || POCHETTE_DEFAUT)}" class="song-img" alt="">
            <div class="song-infos">
                <div class="song-title">${surligner(t.title_surligne)}</div>
                <div class="song-artist">${escape(t.artists_names || 'Artiste inconnu')}</div>
                <div class="search-meta">
                    <span>${duree(t.duration)}</span>
                    ${t.nb_ecoutes ? `<span>${pluriel(t.nb_ecoutes, 'écoute')}</span>` : ''}
                    ${t.nb_playlists ? `<span>${pluriel(t.nb_playlists, 'playlist')}</span>` : ''}
                    ${badges}
                </div>
            </div>
            <div class="search-actions">
                ${t.favori ? `<span class="material-symbols-outlined search-favori" title="Dans vos favoris">favorite</span>` : ''}
                ${playlistId
                    ? `<label class="search-case" title="${t.in_playlist ? 'Dans la playlist' : 'Ajouter à la playlist'}">
                           <input type="checkbox" class="add-btn"
                                  data-track-id="${t.id}" data-playlist-id="${playlistId}"
                                  ${t.in_playlist ? 'checked' : ''}>
                       </label>`
                    : `<button class="buttons material-symbols-outlined search-play" data-play="${t.id}"
                               title="Écouter">play_arrow</button>
                       <button class="buttons material-symbols-outlined">more_vert</button>`}
            </div>
        </div>`;
    }

    function ligneArtiste(a) {
        const genres = a.genres.slice(0, 2)
            .map(g => `<span class="search-badge">${escape(g.name)}</span>`).join('');

        return `
        <div class="mini-song search-item" data-artiste-id="${a.id}">
            <img src="${escape(a.img || POCHETTE_DEFAUT)}" class="song-img search-img-ronde" alt="">
            <div class="song-infos">
                <div class="song-title">${surligner(a.name_surligne)}</div>
                <div class="search-meta">
                    <span>${pluriel(a.nb_titres, 'titre')}</span>
                    ${a.duree_totale ? `<span>${dureeLongue(a.duree_totale)}</span>` : ''}
                    ${genres}
                </div>
            </div>
            <span class="material-symbols-outlined search-chevron">chevron_right</span>
        </div>`;
    }

    function lignePlaylist(p) {
        const tags = p.tags.slice(0, 2)
            .map(t => `<span class="search-badge">${escape(t.name)}</span>`).join('');

        return `
        <div class="mini-song search-item" data-playlist-cible="${p.id}">
            <span class="material-symbols-outlined search-icone-playlist">queue_music</span>
            <div class="song-infos">
                <div class="song-title">${surligner(p.name_surligne)}</div>
                <div class="search-meta">
                    <span>${pluriel(p.nb_titres, 'titre')}</span>
                    ${p.duree_totale ? `<span>${dureeLongue(p.duree_totale)}</span>` : ''}
                    ${p.est_mienne ? '' : `<span>par ${escape(p.auteur || '—')}</span>`}
                    ${tags}
                </div>
            </div>
            <span class="material-symbols-outlined search-chevron">chevron_right</span>
        </div>`;
    }

    let dernieresDonnees = null;
    let filtreActif = 'tout';

    function afficherFiltres(totaux) {
        const onglets = [
            ['tout',      'Tout',      totaux.tout],
            ['musiques',  'Titres',    totaux.musiques],
            ['artistes',  'Artistes',  totaux.artistes],
            ['playlists', 'Playlists', totaux.playlists],
        ].filter(([cle, , n]) => cle === 'tout' || n > 0);

        // Un filtre devenu vide ne doit pas rester sélectionné.
        if (!onglets.some(([cle]) => cle === filtreActif)) filtreActif = 'tout';

        filtres.innerHTML = onglets.map(([cle, libelle, n]) => `
            <button type="button" class="search-onglet ${cle === filtreActif ? 'actif' : ''}"
                    data-filtre="${cle}">
                ${libelle}<span class="search-onglet-nb">${n}</span>
            </button>`).join('');
        filtres.hidden = false;
    }

    function afficherResultats(data, terme) {
        dernieresDonnees = data;
        const { musiques, artistes, playlists, totaux } = data;

        if (!totaux.tout) {
            afficherAucunResultat(terme);
            return;
        }

        afficherFiltres(totaux);

        const section = (cle, titre, hits, rendu) => {
            if (!hits.length) return '';
            if (filtreActif !== 'tout' && filtreActif !== cle) return '';
            return `
            <section class="result-section">
                <h3>${titre}<span class="result-section-nb">${hits.length}</span></h3>
                ${hits.map(rendu).join('')}
            </section>`;
        };

        resultsDiv.innerHTML =
            section('musiques',  'Titres',    musiques,  ligneTitre) +
            section('artistes',  'Artistes',  artistes,  ligneArtiste) +
            section('playlists', 'Playlists', playlists, lignePlaylist);

        // Rebranche le menu contextuel sur les lignes fraîchement rendues.
        if (typeof window.initializeTrackContextMenus === 'function') {
            window.initializeTrackContextMenus();
        }
    }

    // ------------------------------------------------------------- recherche

    let debounceTimer;
    let requeteEnCours = null;

    async function lancerRecherche(terme) {
        // Une frappe rapide peut faire revenir les réponses dans le désordre :
        // on annule la requête précédente plutôt que d'afficher un résultat périmé.
        if (requeteEnCours) requeteEnCours.abort();
        requeteEnCours = new AbortController();

        const formData = new FormData();
        formData.append('search-entry', terme);
        if (playlistId) formData.append('playlist_id', playlistId);

        try {
            const response = await fetch('actions/search.php', {
                method: 'POST',
                body: formData,
                signal: requeteEnCours.signal,
            });
            if (!response.ok) throw new Error(response.status);

            const data = await response.json();
            afficherResultats(data, terme);
            if (data.totaux.tout) memoriserRecente(terme);
        } catch (err) {
            if (err.name === 'AbortError') return; // remplacée par une frappe plus récente
            resultsDiv.innerHTML = '<p class="search-erreur">Erreur lors de la recherche.</p>';
            if (window.showToast) window.showToast('Erreur lors de la recherche', 'error');
        }
    }

    function surSaisie() {
        const terme = input.value.trim();
        boutonVider.hidden = terme === '';

        clearTimeout(debounceTimer);

        if (terme.length < 2) {
            if (requeteEnCours) requeteEnCours.abort();
            afficherAccueil();
            return;
        }

        afficherChargement();
        debounceTimer = setTimeout(() => lancerRecherche(terme), 250);
    }

    input.addEventListener('input', surSaisie);

    input.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            input.value = '';
            surSaisie();
        }
    });

    boutonVider.addEventListener('click', () => {
        input.value = '';
        input.focus();
        surSaisie();
    });

    // ------------------------------------------------------------- clics

    filtres.addEventListener('click', e => {
        const onglet = e.target.closest('.search-onglet');
        if (!onglet) return;
        filtreActif = onglet.dataset.filtre;
        if (dernieresDonnees) afficherResultats(dernieresDonnees, input.value.trim());
    });

    resultsDiv.addEventListener('click', e => {
        // Recherche récente
        const chip = e.target.closest('.search-chip');
        if (chip) {
            input.value = chip.dataset.terme;
            surSaisie();
            input.focus();
            return;
        }

        if (e.target.closest('#vider-recentes')) {
            try { localStorage.removeItem(CLE_RECENTES); } catch (err) {}
            afficherAccueil();
            return;
        }

        // Lecture directe
        const play = e.target.closest('.search-play');
        if (play) {
            e.stopPropagation();
            if (typeof loadTrack === 'function') loadTrack(Number(play.dataset.play));
            return;
        }

        // Le menu contextuel gère lui-même ses boutons
        if (e.target.closest('.buttons.material-symbols-outlined')?.textContent.includes('more')) return;

        /*
         * La case à cocher gère son propre événement « change ». On intercepte
         * quand même le clic ici : sans ça, il remonte jusqu'à .search-item et
         * la navigation vers la fiche du titre partait en meme temps que
         * l'ajout.
         */
        if (e.target.closest('.search-case')) {
            e.stopPropagation();
            return;
        }

        // Navigation vers la page de détail
        const item = e.target.closest('.search-item');
        if (!item) return;

        if (item.dataset.titreId) {
            sessionStorage.setItem('titre_id', item.dataset.titreId);
            navigateTo('library/titre');
        } else if (item.dataset.artisteId) {
            sessionStorage.setItem('artiste_id', item.dataset.artisteId);
            navigateTo('library/artiste');
        } else if (item.dataset.playlistCible) {
            sessionStorage.setItem('playlist_id', item.dataset.playlistCible);
            navigateTo('library/playlist');
        }
    });

    /*
     * Le « + » ne savait dire qu'une chose, une seule fois : une fois coché il
     * se figeait sur « ✓ » et il fallait aller dans la playlist pour défaire.
     * La case à cocher porte les deux sens.
     */
    resultsDiv.addEventListener('change', (e) => {
        const case_ = e.target.closest('.add-btn');
        if (case_) basculerDansPlaylist(case_);
    });

    async function basculerDansPlaylist(case_) {
        const ajouter = case_.checked;
        case_.disabled = true;

        const corps = new URLSearchParams({
            track_id: case_.dataset.trackId,
            playlist_id: case_.dataset.playlistId,
        });

        try {
            const res = await fetch(
                ajouter ? 'actions/add_to_playlist.php' : 'actions/remove_track_from_playlist.php',
                { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: corps }
            );
            const data = await res.json();

            if (!data.success) {
                // La case revient à son état d'avant : la laisser cochée sur un
                // échec laisserait croire le titre rangé.
                case_.checked = !ajouter;
                if (window.showToast) window.showToast(data.message || 'Opération impossible', 'error');
            } else if (window.showToast) {
                window.showToast(ajouter ? 'Ajouté à la playlist' : 'Retiré de la playlist');
            }
        } catch (e) {
            case_.checked = !ajouter;
            if (window.showToast) window.showToast('Erreur réseau', 'error');
        } finally {
            case_.disabled = false;
        }
    }

    // État initial. Le champ peut être pré-rempli si la page est réaffichée
    // par le routeur : on aligne l'affichage sur son contenu réel.
    boutonVider.hidden = input.value.trim() === '';
    afficherAccueil();
    input.focus();
})();
