/*
 * Service worker d'Unison.
 *
 * Son rôle est étroit, et c'est voulu : rendre l'application installable et
 * faire qu'elle s'ouvre sans attendre le réseau pour son habillage (feuille de
 * style, scripts, polices, icônes). Il ne rend PAS l'application utilisable
 * hors ligne — la musique est diffusée depuis le serveur, et les pages
 * dépendent d'une session. Promettre le hors-ligne ici reviendrait à afficher
 * une bibliothèque qu'on ne peut pas écouter.
 *
 * Ce qui n'est jamais intercepté, et pourquoi :
 *
 *   - tout ce qui n'est pas GET      : une écriture ne se sert pas d'un cache ;
 *   - /actions/*                     : authentification, JSON, écritures — et
 *                                      surtout actions/stream.php, qui répond
 *                                      aux requêtes Range en 206. Un cache qui
 *                                      s'en mêle casse le déplacement dans le
 *                                      morceau, y compris depuis la
 *                                      notification Android ;
 *   - toute requête portant Range    : même raison, par précaution ;
 *   - /pages/*                       : fragments rendus par le routeur, propres
 *                                      à la session et au contenu du moment.
 *
 * Les URL d'habillage portent déjà « ?v=<filemtime> » (voir assetVersionne()
 * dans includes/auth.php) : une version neuve est donc une clé de cache neuve,
 * et l'ancienne disparaît avec le ménage fait à l'activation.
 */

const VERSION = 'unison-v2';
const COQUILLE = VERSION + '-coquille';
const HABILLAGE = VERSION + '-habillage';

const PAGE_HORS_LIGNE = '/offline.html';

/* Le strict nécessaire pour afficher quelque chose sans réseau. */
const A_PRECHARGER = [
    PAGE_HORS_LIGNE,
    '/icones/icon-192.png',
];

self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(COQUILLE)
            .then((c) => c.addAll(A_PRECHARGER))
            // Un préchargement raté ne doit pas empêcher l'installation :
            // l'application marche très bien sans page hors-ligne.
            .catch(() => undefined)
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((noms) => Promise.all(
                noms.filter((n) => n !== COQUILLE && n !== HABILLAGE)
                    .map((n) => caches.delete(n))
            ))
            .then(() => self.clients.claim())
    );
});

/** Habillage : feuille de style, scripts, icônes, polices Google. */
function estHabillage(url) {
    if (url.origin === self.location.origin) {
        return /^\/(styles|scripts|icones)\//.test(url.pathname)
            || url.pathname === '/manifest.webmanifest';
    }
    return url.hostname === 'fonts.googleapis.com'
        || url.hostname === 'fonts.gstatic.com';
}

function aLaisserPasser(requete, url) {
    if (requete.method !== 'GET') return true;
    if (requete.headers.has('range')) return true;
    if (url.origin !== self.location.origin) return !estHabillage(url);
    return url.pathname.startsWith('/actions/')
        || url.pathname.startsWith('/pages/');
}

/*
 * Servir depuis le cache, rafraîchir derrière.
 *
 * L'affichage ne dépend jamais du réseau, et la version suivante est prête au
 * chargement d'après. Comme les URL sont versionnées, « périmé » ne veut dire
 * ici que « la réponse d'avant pour cette même version du fichier ».
 */
async function cacheEtRafraichissement(requete) {
    const cache = await caches.open(HABILLAGE);
    const connu = await cache.match(requete);

    const reseau = fetch(requete).then((reponse) => {
        // Les réponses opaques (polices en CORS) ont un status 0 : elles sont
        // stockables telles quelles et le navigateur sait les relire.
        if (reponse && (reponse.ok || reponse.type === 'opaque')) {
            cache.put(requete, reponse.clone());
        }
        return reponse;
    }).catch(() => undefined);

    return connu || reseau || fetch(requete);
}

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);

    if (aLaisserPasser(e.request, url)) return;

    if (estHabillage(url)) {
        e.respondWith(cacheEtRafraichissement(e.request));
        return;
    }

    /*
     * Navigations : toujours le réseau. Une page d'Unison dépend de la session
     * et du contenu du moment ; en servir une copie reviendrait à montrer la
     * bibliothèque d'une session fermée. Sans réseau, on le dit.
     */
    if (e.request.mode === 'navigate') {
        e.respondWith(
            fetch(e.request).catch(() => caches.match(PAGE_HORS_LIGNE))
        );
    }
});
