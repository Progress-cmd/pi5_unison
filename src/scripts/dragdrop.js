(function() {
    let draggedElement = null;
    let draggedTrackId = null;
    let touchStartY = 0;
    let touchStartX = 0;
    let isDragging = false;
    let dragAutorise = false;      // l'appui long a-t-il eu lieu ?
    let minuteurAppui = null;

    /*
     * Le déplacement tactile ne s'engage plus à la distance parcourue.
     *
     * Le seuil précédent, unique, comparait 10 px aussi bien en X qu'en Y :
     * un simple défilement dépasse 10 px verticaux dès le premier mouvement,
     * le glisser s'armait donc immédiatement et le preventDefault() qui suit
     * bloquait le scroll. La file d'attente devenait impossible à parcourir
     * au doigt.
     *
     * Un appui maintenu lève l'ambiguïté : personne ne garde le doigt immobile
     * une demi-seconde pour faire défiler une liste, alors que c'est le geste
     * attendu pour saisir un élément. Tant qu'il n'a pas eu lieu, le navigateur
     * garde la main et défile normalement.
     */
    const DUREE_APPUI = 450;       // ms avant que le déplacement soit permis
    const TOLERANCE_APPUI = 10;    // px : au-delà, c'est un défilement

    window.enableDragDrop = function(container, playlistId) {
        if (!container) return;
        
        const items = container.querySelectorAll('[data-track-id]');
        items.forEach((item, index) => {
            item.draggable = true;
            item.setAttribute('data-position', index);

            // Events pour desktop
            item.addEventListener('dragstart', handleDragStart);
            item.addEventListener('dragend', handleDragEnd);
            item.addEventListener('dragover', handleDragOver);
            item.addEventListener('drop', handleDrop);
            item.addEventListener('dragenter', handleDragEnter);
            item.addEventListener('dragleave', handleDragLeave);

            // Events pour mobile
            /*
             * touchstart en écoute passive : il ne fait qu'armer un minuteur,
             * jamais de preventDefault(). Le déclarer passif permet au
             * navigateur de lancer le défilement sans attendre l'exécution du
             * gestionnaire — c'est ce qui rend la liste fluide au doigt.
             *
             * touchmove ne peut pas l'être : il lui faut preventDefault() une
             * fois le déplacement engagé.
             */
            item.addEventListener('touchstart', handleTouchStart, { passive: true });
            item.addEventListener('touchmove', handleTouchMove, { passive: false });
            item.addEventListener('touchend', handleTouchEnd, { passive: true });

            // Doigt interrompu par le système (appel entrant, geste de bord…) :
            // sans ça, l'élément restait saisi et grisé indéfiniment.
            item.addEventListener('touchcancel', handleTouchEnd, { passive: true });
        });

        container.dataset.playlistId = playlistId;
    };

    // ===== DESKTOP DRAG & DROP =====
    function handleDragStart(e) {
        draggedElement = this;
        draggedTrackId = this.dataset.trackId;

        this.style.opacity = '0.5';
        this.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', this.innerHTML);
    }

    function handleDragEnd(e) {
        draggedElement.style.opacity = '';
        draggedElement.classList.remove('dragging');
        document.querySelectorAll('.drag-over').forEach(el => {
            el.classList.remove('drag-over');
        });
    }

    function handleDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }

    function handleDragEnter(e) {
        if (this !== draggedElement) {
            this.classList.add('drag-over');
        }
    }

    function handleDragLeave(e) {
        if (e.target === this) {
            this.classList.remove('drag-over');
        }
    }

    function handleDrop(e) {
        e.preventDefault();
        e.stopPropagation();

        if (this === draggedElement) return;

        const container = this.closest('[data-playlist-id]');
        const playlistId = container.dataset.playlistId;

        const items = container.querySelectorAll('[data-track-id]');
        const draggedIndex = Array.from(items).indexOf(draggedElement);
        const dropIndex = Array.from(items).indexOf(this);

        if (draggedIndex < dropIndex) {
            this.parentNode.insertBefore(draggedElement, this.nextSibling);
        } else {
            this.parentNode.insertBefore(draggedElement, this);
        }

        updatePositionsInDB(container, playlistId);
        this.classList.remove('drag-over');
    }

    // ===== MOBILE TOUCH DRAG & DROP =====
    function annulerAppui() {
        if (minuteurAppui) {
            clearTimeout(minuteurAppui);
            minuteurAppui = null;
        }
    }

    function handleTouchStart(e) {
        draggedElement = this;
        draggedTrackId = this.dataset.trackId;
        touchStartY = e.touches[0].clientY;
        touchStartX = e.touches[0].clientX;
        isDragging = false;
        dragAutorise = false;

        const element = this;
        annulerAppui();
        minuteurAppui = setTimeout(() => {
            dragAutorise = true;
            // Retour tactile : sans lui, rien ne signale que l'élément est
            // saisi, et l'utilisateur relâche avant d'avoir compris.
            element.classList.add('drag-pret');
            if (navigator.vibrate) navigator.vibrate(15);
        }, DUREE_APPUI);
    }

    function handleTouchMove(e) {
        if (!draggedElement) return;

        const touch = e.touches[0];
        const distX = Math.abs(touch.clientX - touchStartX);
        const distY = Math.abs(touch.clientY - touchStartY);

        /*
         * Le doigt a bougé avant la fin de l'appui : c'est un défilement.
         * On abandonne la saisie et on ne touche à rien — le navigateur fait
         * défiler comme si ce script n'existait pas.
         */
        if (!dragAutorise) {
            if (distX > TOLERANCE_APPUI || distY > TOLERANCE_APPUI) {
                annulerAppui();
                draggedElement.classList.remove('drag-pret');
                draggedElement = null;
            }
            return;
        }

        if (!isDragging) {
            isDragging = true;
            draggedElement.style.opacity = '0.5';
            draggedElement.classList.add('dragging');
        }

        e.preventDefault();

        const elementBelow = document.elementFromPoint(touch.clientX, touch.clientY);
        const targetItem = elementBelow?.closest('[data-track-id]');

        if (targetItem && targetItem !== draggedElement) {
            targetItem.classList.add('drag-over');
        } else {
            document.querySelectorAll('.drag-over').forEach(el => {
                el.classList.remove('drag-over');
            });
        }
    }

    function handleTouchEnd(e) {
        annulerAppui();

        if (!draggedElement) return;

        draggedElement.style.opacity = '';
        draggedElement.classList.remove('dragging');
        draggedElement.classList.remove('drag-pret');

        if (isDragging) {
            const touch = e.changedTouches[0];
            const elementBelow = document.elementFromPoint(touch.clientX, touch.clientY);
            const targetItem = elementBelow?.closest('[data-track-id]');

            if (targetItem && targetItem !== draggedElement) {
                const container = targetItem.closest('[data-playlist-id]');
                const playlistId = container.dataset.playlistId;

                const items = container.querySelectorAll('[data-track-id]');
                const draggedIndex = Array.from(items).indexOf(draggedElement);
                const dropIndex = Array.from(items).indexOf(targetItem);

                if (draggedIndex < dropIndex) {
                    targetItem.parentNode.insertBefore(draggedElement, targetItem.nextSibling);
                } else {
                    targetItem.parentNode.insertBefore(draggedElement, targetItem);
                }

                updatePositionsInDB(container, playlistId);
            }
        }

        document.querySelectorAll('.drag-over').forEach(el => {
            el.classList.remove('drag-over');
        });

        draggedElement = null;
        isDragging = false;
        dragAutorise = false;
    }

    // ===== MISE À JOUR BDD =====
    async function updatePositionsInDB(container, playlistId) {
        const items = container.querySelectorAll('[data-track-id]');

        for (let i = 0; i < items.length; i++) {
            const trackId = items[i].dataset.trackId;

            try {
                const response = await fetch('actions/reorder_tracks.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `playlist_id=${playlistId}&track_id=${trackId}&position=${i}`
                });

                const data = await response.json();
                if (!data.success) {
                    console.error('Erreur reordering:', data);
                }
            } catch (error) {
                console.error('Erreur réseau:', error);
            }
        }
    }
})();
