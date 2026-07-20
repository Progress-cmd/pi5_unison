/**
 * Orchestration de l'import multiple, globale et persistante :
 * l'import continue même si l'utilisateur change de page, et un indicateur
 * de progression reste visible partout. La page d'import s'y synchronise.
 */
(function () {
    const state = {
        running: false,
        items: [],        // { title, status: 'pending'|'loading'|'done'|'error' }
        currentIndex: -1,
        ok: 0,
        fail: 0,
    };

    window.BulkImport = {
        state,
        start,
        isRunning: () => state.running,
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
        emit();

        // 1) Développe les liens (playlists incluses) en liste de vidéos
        let tracks = [];
        try {
            const res = await fetch('actions/import_expand.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'text=' + encodeURIComponent(text)
            });
            const data = await res.json();
            tracks = data.tracks || [];
        } catch (e) { /* réseau : liste vide → message ci-dessous */ }

        if (tracks.length === 0) {
            state.running = false;
            emit();
            window.showToast && window.showToast('Aucune vidéo trouvée', 'error');
            return;
        }

        state.items = tracks.map(t => ({ title: t.title, status: 'pending' }));
        emit();

        // 2) Importe chaque vidéo séquentiellement (un seul téléchargement à la fois)
        for (let i = 0; i < tracks.length; i++) {
            state.currentIndex = i;
            state.items[i].status = 'loading';
            emit();
            try {
                const res = await fetch('actions/import_bulk.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'url=' + encodeURIComponent(tracks[i].url)
                });
                const data = await res.json();
                if (data.success) {
                    state.items[i].status = 'done';
                    if (data.title) state.items[i].title = data.title + ' — ' + data.artist;
                    state.ok++;
                } else {
                    state.items[i].status = 'error';
                    state.fail++;
                }
            } catch (e) {
                state.items[i].status = 'error';
                state.fail++;
            }
            emit();
        }

        state.running = false;
        state.currentIndex = -1;
        emit();
        window.showToast && window.showToast(
            `${state.ok} importé(s)` + (state.fail ? `, ${state.fail} échec(s)` : ''),
            state.fail ? 'error' : 'success'
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
            el.querySelector('.imp-count').textContent = total ? `${done}/${total}` : '…';
            el.querySelector('.imp-title').textContent = cur ? cur.title : 'Analyse des liens…';
            el.querySelector('.imp-bar-fill').style.width = total ? (done / total * 100) + '%' : '0%';
            el.classList.add('visible');
        } else if (el.classList.contains('visible')) {
            // Fin d'import : on affiche 100 % un court instant puis on masque
            el.querySelector('.imp-count').textContent = `${done}/${total}`;
            el.querySelector('.imp-title').textContent = 'Terminé';
            el.querySelector('.imp-bar-fill').style.width = '100%';
            if (hideTimer) clearTimeout(hideTimer);
            hideTimer = setTimeout(() => el.classList.remove('visible'), 3500);
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
