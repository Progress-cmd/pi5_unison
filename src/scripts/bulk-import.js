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
        termine: false,   // un import s'est achevé : le bilan reste affiché
    };

    window.BulkImport = {
        state,
        start,
        isRunning: () => state.running,
        echecs: () => state.items.filter(i => i.status === 'error'),
    };

    function emit() {
        window.dispatchEvent(new CustomEvent('bulkimport:update', { detail: state }));
    }

    async function start(text) {
        if (state.running) {
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
        state.termine = false;
        emit();

        // 1) Développe les liens (playlists incluses) en liste de vidéos
        let tracks = [];
        let echecsAnalyse = [];
        try {
            const res = await fetch('actions/import_expand.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'text=' + encodeURIComponent(text)
            });
            const data = await res.json();
            tracks = data.tracks || [];
            echecsAnalyse = data.echecs || [];
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

        const debut = state.items.length;
        state.items.push(...tracks.map(t => ({ title: t.title, url: t.url, status: 'pending' })));
        emit();

        if (tracks.length === 0) {
            state.running = false;
            state.termine = true;
            state.currentIndex = -1;
            emit();
            annoncerBilan();
            return;
        }

        // 2) Importe chaque vidéo séquentiellement (un seul téléchargement à la fois)
        for (let i = 0; i < tracks.length; i++) {
            const idx = debut + i;
            state.currentIndex = idx;
            state.items[idx].status = 'loading';
            emit();
            try {
                const res = await fetch('actions/import_bulk.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'url=' + encodeURIComponent(tracks[i].url)
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);

                const data = await res.json();
                if (data.success) {
                    state.items[idx].status = 'done';
                    if (data.title) state.items[idx].title = data.title + ' — ' + data.artist;
                    state.ok++;
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

    function annoncerBilan() {
        if (!window.showToast) return;

        if (!state.fail) {
            window.showToast(`${state.ok} titre(s) importé(s)`, 'success');
            return;
        }

        // Un échec ne doit pas pouvoir passer inaperçu : le toast détaille la
        // première raison, et reste affiché jusqu'à ce qu'on le ferme.
        const echecs = window.BulkImport.echecs();
        const detail = state.fail === 1
            ? `« ${echecs[0].title} » : ${echecs[0].raison}`
            : `${state.fail} échecs — voir le détail sur la page Importation`;

        window.showToast(
            `${state.ok} importé(s), ${state.fail} échec(s).<br>${detail}`,
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
