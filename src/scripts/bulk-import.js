/**
 * Orchestration de l'import multiple, globale et persistante :
 * l'import continue même si l'utilisateur change de page, et un indicateur
 * de progression reste visible partout. La page d'import s'y synchronise.
 */
(function () {
    const state = {
        running: false,
        // { title, url, status: 'pending'|'loading'|'done'|'error', raison }
        items: [],
        currentIndex: -1,
        ok: 0,
        fail: 0,
        existants: 0,     // déjà en base : ni importés, ni en échec
        termine: false,   // un import s'est achevé : le bilan reste affiché

        /*
         * Analyse terminée, téléchargement pas encore lancé.
         *
         * L'analyse et le téléchargement s'enchaînaient sans respiration : un
         * lien de playlist collé par mégarde partait chercher trente titres
         * avant qu'on ait pu réagir. La liste développée est désormais
         * présentée, et rien n'est téléchargé tant qu'elle n'est pas validée.
         */
        enAttente: false,
        aConfirmer: [],   // { title, url } développés, prêts à partir
        album: null,      // { titre, artiste, source } si la playlist en est un
    };

    window.BulkImport = {
        state,
        start,
        confirmer,
        annuler,
        isRunning: () => state.running,
        enAttente: () => state.enAttente,
        echecs: () => state.items.filter(i => i.status === 'error'),
    };

    function emit() {
        window.dispatchEvent(new CustomEvent('bulkimport:update', { detail: state }));
    }

    async function start(text) {
        if (state.running || state.enAttente) {
            window.showToast && window.showToast('Un import est déjà en cours', 'error');
            return;
        }
        text = (text || '').trim();
        if (!text) {
            window.showToast && window.showToast('Collez au moins un lien', 'error');
            return;
        }

        state.running = true;
        state.items = [];
        state.currentIndex = -1;
        state.ok = 0;
        state.fail = 0;
        state.existants = 0;
        state.termine = false;
        state.enAttente = false;
        state.aConfirmer = [];
        state.album = null;
        emit();

        // 1) Développe les liens (playlists incluses) en liste de vidéos
        let tracks = [];
        let echecsAnalyse = [];
        let album = null;
        try {
            const res = await fetch('actions/import_expand.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'text=' + encodeURIComponent(text)
            });
            const data = await res.json();
            tracks = data.tracks || [];
            echecsAnalyse = data.echecs || [];
            // Un seul album par lot : plusieurs liens d'albums collés ensemble
            // restent importés, mais seul le premier est reconstitué comme album.
            album = (data.albums && data.albums[0]) || null;
        } catch (e) {
            state.running = false;
            state.termine = true;
            emit();
            window.showToast && window.showToast(
                "L'analyse des liens a échoué (réseau ou serveur)", 'error', 0);
            return;
        }

        /*
         * Les liens que l'analyse n'a pas pu développer entrent dans la liste
         * comme échecs. Sans ça ils disparaissaient purement et simplement, et
         * le seul indice restant était un total plus petit que prévu.
         */
        state.items = echecsAnalyse.map(e => ({
            title:  e.lien,
            url:    e.lien,
            status: 'error',
            raison: e.raison,
        }));
        state.fail = state.items.length;

        state.items.push(...tracks.map(t => ({
            title: t.title, url: t.url, status: 'pending', piste: t.piste,
        })));

        state.album = album;

        if (tracks.length === 0) {
            state.running = false;
            state.termine = true;
            state.currentIndex = -1;
            emit();
            annoncerBilan();
            return;
        }

        /*
         * Arrêt ici : rien n'est téléchargé tant que la liste n'est pas
         * validée. « running » redevient faux pour que l'indicateur global
         * ne prétende pas qu'un import tourne — il n'en tourne aucun.
         */
        state.running = false;
        state.enAttente = true;
        state.aConfirmer = tracks;
        emit();
    }

    /** Lance le téléchargement de la liste développée et validée. */
    async function confirmer() {
        if (!state.enAttente) return;

        const tracks = state.aConfirmer;
        const debut = state.items.length - tracks.length;

        state.enAttente = false;
        state.aConfirmer = [];
        state.running = true;
        emit();

        // 2) Importe chaque vidéo séquentiellement (un seul téléchargement à la fois)
        for (let i = 0; i < tracks.length; i++) {
            const idx = debut + i;
            state.currentIndex = idx;
            state.items[idx].status = 'loading';
            emit();
            try {
                const corps = new URLSearchParams({ url: state.items[idx].url });
                if (state.album) {
                    corps.set('album_titre', state.album.titre || '');
                    corps.set('album_artiste', state.album.artiste || '');
                    if (state.album.source) corps.set('album_source', state.album.source);
                    if (state.items[idx].piste) corps.set('album_piste', String(state.items[idx].piste));
                }

                const res = await fetch('actions/import_bulk.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: corps,
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);

                const data = await res.json();
                if (data.success) {
                    /*
                     * Un titre déjà présent n'est pas un import : le distinguer
                     * évite de croire qu'on vient de télécharger douze morceaux
                     * alors qu'on n'a rien récupéré.
                     */
                    state.items[idx].status = data.existant ? 'existant' : 'done';
                    if (data.title) {
                        state.items[idx].title = data.artist
                            ? data.title + ' — ' + data.artist
                            : data.title;
                    }
                    if (data.existant) { state.existants++; } else { state.ok++; }
                } else {
                    state.items[idx].status = 'error';
                    state.items[idx].raison = data.message || 'Échec sans détail';
                    state.fail++;
                }
            } catch (e) {
                state.items[idx].status = 'error';
                state.items[idx].raison = 'Le serveur n\'a pas répondu';
                state.fail++;
            }
            emit();
        }

        state.running = false;
        state.termine = true;
        state.currentIndex = -1;
        emit();
        annoncerBilan();
    }

    /** Abandonne avant tout téléchargement : rien n'a été récupéré. */
    function annuler() {
        if (!state.enAttente) return;

        const echecs = state.items.filter(i => i.status === 'error');

        state.enAttente = false;
        state.aConfirmer = [];
        state.running = false;
        state.termine = false;
        state.currentIndex = -1;
        // Les échecs d'analyse restent : ils renseignent sur les liens fautifs.
        state.items = echecs;
        emit();

        window.showToast && window.showToast('Import annulé', 'success', 3000);
    }

    function annoncerBilan() {
        if (!window.showToast) return;

        // « Déjà en base » n'est ni un succès ni un échec : le taire ferait
        // croire à un import silencieusement incomplet.
        const dejaLa = state.existants
            ? `, ${state.existants} déjà en base`
            : '';

        if (!state.fail) {
            window.showToast(`${state.ok} titre(s) importé(s)${dejaLa}`, 'success');
            return;
        }

        // Un échec ne doit pas pouvoir passer inaperçu : le toast détaille la
        // première raison, et reste affiché jusqu'à ce qu'on le ferme.
        const echecs = window.BulkImport.echecs();
        const detail = state.fail === 1
            ? `« ${echecs[0].title} » : ${echecs[0].raison}`
            : `${state.fail} échecs — voir le détail sur la page Importation`;

        window.showToast(
            `${state.ok} importé(s)${dejaLa}, ${state.fail} échec(s).<br>${detail}`,
            'error',
            0
        );
    }

    // ---- Indicateur de progression global (persiste entre les pages) ----
    let hideTimer = null;

    function renderIndicator() {
        const el = document.getElementById('import-indicator');
        if (!el) return;

        const total = state.items.length;
        const done = state.items.filter(i => i.status === 'done' || i.status === 'error').length;

        if (state.running) {
            if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
            const cur = state.items[state.currentIndex];
            el.classList.toggle('en-echec', state.fail > 0);
            el.querySelector('.imp-count').textContent = total ? `${done}/${total}` : '…';
            el.querySelector('.imp-title').textContent = cur ? cur.title : 'Analyse des liens…';
            el.querySelector('.imp-bar-fill').style.width = total ? (done / total * 100) + '%' : '0%';
            el.classList.add('visible');
        } else if (el.classList.contains('visible')) {
            el.querySelector('.imp-count').textContent = `${state.ok}/${total}`;
            el.querySelector('.imp-bar-fill').style.width = '100%';
            el.classList.toggle('en-echec', state.fail > 0);

            if (state.fail > 0) {
                // En cas d'échec l'indicateur ne s'efface pas tout seul :
                // il reste comme point d'entrée vers le détail.
                el.querySelector('.imp-title').textContent =
                    `${state.fail} échec(s) — voir le détail`;
                if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
            } else {
                el.querySelector('.imp-title').textContent = 'Terminé';
                if (hideTimer) clearTimeout(hideTimer);
                hideTimer = setTimeout(() => el.classList.remove('visible'), 3500);
            }
        }
    }

    window.addEventListener('bulkimport:update', renderIndicator);

    // Clic sur l'indicateur → ouvre la page d'import
    document.addEventListener('click', (e) => {
        if (e.target.closest('#import-indicator') && typeof navigateTo === 'function') {
            navigateTo('import');
        }
    });
})();
