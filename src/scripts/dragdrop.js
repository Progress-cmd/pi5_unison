(function() {
    let draggedElement = null;
    let draggedTrackId = null;
    let draggedPlaylistId = null;

    window.enableDragDrop = function(container, playlistId) {
        if (!container) return;
        
        const items = container.querySelectorAll('[data-track-id]');
        items.forEach((item, index) => {
            item.draggable = true;
            item.setAttribute('data-position', index);

            item.addEventListener('dragstart', handleDragStart);
            item.addEventListener('dragend', handleDragEnd);
            item.addEventListener('dragover', handleDragOver);
            item.addEventListener('drop', handleDrop);
            item.addEventListener('dragenter', handleDragEnter);
            item.addEventListener('dragleave', handleDragLeave);
        });

        container.dataset.playlistId = playlistId;
    };

    function handleDragStart(e) {
        draggedElement = this;
        draggedTrackId = this.dataset.trackId;
        draggedPlaylistId = this.closest('[data-playlist-id]')?.dataset.playlistId;

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
