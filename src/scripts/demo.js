/**
 * Confort d'affichage du mode démonstration.
 *
 * Le blocage réel se fait côté serveur (refuserSiDemo dans includes/auth.php).
 * Ce script ne fait qu'expliquer le refus à l'utilisateur : sans lui, une
 * action bloquée resterait silencieuse et donnerait l'impression d'un bug.
 */
(function () {
    if (!window.UNISON_DEMO) return;

    const fetchOriginal = window.fetch.bind(window);

    window.fetch = async function (...args) {
        const reponse = await fetchOriginal(...args);

        if (reponse.status !== 403) return reponse;

        // On lit sur un clone, pour laisser le corps intact à l'appelant.
        try {
            const data = await reponse.clone().json();
            if (data && data.demo) {
                signaler(data.message);
            }
        } catch (e) {
            // Réponse 403 non-JSON (le streaming, par exemple) : rien à dire ici.
        }

        return reponse;
    };

    // Un seul message à la fois : cliquer trois fois ne doit pas empiler trois toasts.
    let enCours = false;

    function signaler(message) {
        if (enCours) return;
        enCours = true;
        setTimeout(() => { enCours = false; }, 3000);

        if (typeof window.showToast === 'function') {
            window.showToast(message || 'Mode démonstration : action désactivée.', 'error', 4000);
        }
    }

    // Le player ne passe pas par fetch pour l'audio : on intercepte l'échec
    // de lecture d'un titre non diffusable en démonstration.
    document.addEventListener('error', function (e) {
        if (e.target && e.target.tagName === 'AUDIO') {
            signaler("Ce titre n'est pas diffusable en mode démonstration.");
        }
    }, true);
})();
