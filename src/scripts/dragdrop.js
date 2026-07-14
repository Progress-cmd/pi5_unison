(function() {
    let draggedElement = null;
    let draggedTrackId = null;
    let touchStartY = 0;

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
            item.addEventListener('touchstart', handleTouchStart, false);
            item.addEventListener('touchmove', handleTouchMove, false);
            item.addEventListener('touchend', handleTouchEnd, false);
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
    function handleTouchStart(e) {
        draggedElement = this;
        draggedTrackId = this.dataset.trackId;
        touchStartY = e.touches[0].clientY;

        this.style.opacity = '0.5';
        this.classList.add('dragging');
    }

    function handleTouchMove(e) {
        if (!draggedElement) return;
        
        e.preventDefault();
        
        const touch = e.touches[0];
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
        if (!draggedElement) return;
        
        draggedElement.style.opacity = '';
        draggedElement.classList.remove('dragging');
        
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
        
        document.querySelectorAll('.drag-over').forEach(el => {
            el.classList.remove('drag-over');
        });
        
        draggedElement = null;
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
