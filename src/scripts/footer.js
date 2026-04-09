const player = document.getElementById('playerLink');
const closeBtn = document.getElementById('closePlayer');
const extend = player.querySelector('.extend');

// --- Audio setup ---
const audio = new Audio('https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3');
// Remplace l'URL par ton vrai fichier audio

// --- Expand / Collapse ---
player.addEventListener('click', function(e) {
    if (e.target.closest('button')) return;
    extend.classList.remove('closing');
    player.classList.add('expanded');
});

closeBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    extend.classList.add('closing');
    extend.addEventListener('animationend', () => {
        player.classList.remove('expanded');
        extend.classList.remove('closing');
    }, { once: true });
});

// --- Play / Pause ---
function updatePlayBtns() {
    const icon = audio.paused ? 'play_arrow' : 'pause';
    document.querySelectorAll('.play-btn .material-symbols-outlined').forEach(el => {
        el.textContent = icon;
    });
}

document.querySelectorAll('.play-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        audio.paused ? audio.play() : audio.pause();
    });
});

audio.addEventListener('play', updatePlayBtns);
audio.addEventListener('pause', updatePlayBtns);

// --- Progress bars ---
audio.addEventListener('timeupdate', () => {
    if (!audio.duration) return;
    const pct = (audio.currentTime / audio.duration) * 100;

    // Mini player
    const miniBar = document.querySelector('.mini-progress-current');
    if (miniBar) miniBar.style.width = pct + '%';

    // Expanded player
    const expBar = document.querySelector('.expanded-progress-current');
    if (expBar) expBar.style.width = pct + '%';

    // Temps affiché
    document.querySelector('.time-current').textContent = formatTime(audio.currentTime);
    document.querySelector('.time-total').textContent = formatTime(audio.duration);
});

function formatTime(s) {
    if (isNaN(s)) return '0:00';
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60).toString().padStart(2, '0');
    return `${m}:${sec}`;
}

// --- Clic sur la barre de progression ---
document.querySelector('.expanded-progress-bar').addEventListener('click', function(e) {
    const rect = this.getBoundingClientRect();
    const ratio = (e.clientX - rect.left) / rect.width;
    audio.currentTime = ratio * audio.duration;
});

// --- Next / Prev (logique de base, à adapter à ta playlist) ---
document.querySelectorAll('.next-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        audio.currentTime = Math.min(audio.currentTime + 15, audio.duration);
    });
});

document.querySelectorAll('.prev-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        audio.currentTime = Math.max(audio.currentTime - 15, 0);
    });
});

// --- Like / Favorite ---
document.querySelectorAll('.like-btn, .favorite-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const icon = btn.querySelector('.material-symbols-outlined');
        const active = btn.classList.toggle('active');
        icon.style.color = active ? '#C8593A' : '';
        icon.style.fontVariationSettings = active ? "'FILL' 1" : "'FILL' 0";
    });
});

// --- Shuffle ---
document.querySelector('.shuffle-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    const btn = e.currentTarget;
    btn.classList.toggle('active');
    btn.style.color = btn.classList.contains('active') ? '#C8593A' : '';
});

// --- Repeat ---
document.querySelector('.repeat-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    const btn = e.currentTarget;
    btn.classList.toggle('active');
    audio.loop = btn.classList.contains('active');
    btn.style.color = audio.loop ? '#C8593A' : '';
});

// --- Volume ---
document.querySelector('.volume-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    audio.muted = !audio.muted;
    const icon = e.currentTarget.querySelector('.material-symbols-outlined');
    icon.textContent = audio.muted ? 'volume_off' : 'volume_up';
});

// --- Fin de piste ---
audio.addEventListener('ended', () => {
    updatePlayBtns();
    document.querySelector('.mini-progress-current').style.width = '0%';
    document.querySelector('.expanded-progress-current').style.width = '0%';
});