/*
 * Jeton anti-CSRF posé automatiquement sur toute écriture.
 *
 * Les actions utilisateur n'en portaient aucun : seuls la connexion et la
 * section d'administration étaient protégées. En production
 * `session.cookie_samesite = "Strict"` empêche déjà le navigateur d'envoyer
 * le cookie de session depuis un autre site — le jeton est donc une seconde
 * ligne, pas la seule. Elle vaut d'être posée : la garde ne dépend plus alors
 * d'un réglage d'INI qu'un environnement mal configuré pourrait perdre.
 *
 * L'enrobage de fetch() évite de reprendre la vingtaine d'appels existants et,
 * surtout, garantit que le prochain appel écrit sera couvert sans que
 * personne ait à y penser.
 */
(function () {
    const jeton = window.UNISON_CSRF;
    if (!jeton) return;

    const fetchOrigine = window.fetch.bind(window);
    const METHODES_ECRITURE = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

    /** Une requête vers un autre domaine ne doit jamais recevoir notre jeton. */
    function memeOrigine(cible) {
        try {
            return new URL(cible, location.href).origin === location.origin;
        } catch (e) {
            return false;
        }
    }

    window.fetch = function (ressource, options) {
        const opts = options || {};
        const methode = (opts.method || 'GET').toUpperCase();

        const url = typeof ressource === 'string' ? ressource
                  : (ressource instanceof Request ? ressource.url : String(ressource));

        if (!METHODES_ECRITURE.has(methode) || !memeOrigine(url)) {
            return fetchOrigine(ressource, options);
        }

        const corps = opts.body;

        if (corps instanceof FormData) {
            // `csrf` et non `token` : l'import a son propre champ `token`,
            // à usage unique, qu'il ne faut surtout pas écraser.
            if (!corps.has('csrf')) corps.append('csrf', jeton);

        } else if (corps instanceof URLSearchParams) {
            if (!corps.has('csrf')) corps.append('csrf', jeton);

        } else if (typeof corps === 'string') {
            if (!/(^|&)csrf=/.test(corps)) {
                opts.body = corps + (corps === '' ? '' : '&') + 'csrf=' + encodeURIComponent(jeton);
            }

        } else if (corps === undefined || corps === null) {
            // Écriture sans corps (lire_tout_aleatoire) : on en crée un.
            opts.body = 'csrf=' + encodeURIComponent(jeton);
            opts.headers = Object.assign(
                { 'Content-Type': 'application/x-www-form-urlencoded' },
                opts.headers || {}
            );
        }

        return fetchOrigine(ressource, opts);
    };

    /**
     * Jeton pour les envois qui ne passent pas par fetch : sendBeacon au
     * déchargement de la page, formulaires rendus côté serveur.
     */
    window.ajouterJetonCsrf = function (parametres) {
        if (!parametres.has('csrf')) parametres.append('csrf', jeton);
        return parametres;
    };
})();
