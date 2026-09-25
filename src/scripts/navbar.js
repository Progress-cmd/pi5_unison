/**
 * Barre de navigation : pastille de messages et entrées repliées.
 *
 * Deux comportements qui vivent hors des pages, parce que la barre survit à
 * la navigation du routeur.
 */
(function () {
    /* ---------- Pastille de messages non lus ---------- */

    const pastille = document.getElementById('nav-messages-pastille');

    /*
     * Appelée par le battement de présence (scripts/presence.js), qui rapporte
     * les non-lus, et par le chat lui-même quand il vient de tout lire.
     */
    window.majPastilleMessages = function (nombre) {
        if (!pastille) return;

        const n = Number(nombre) || 0;
        pastille.hidden = n === 0;
        // Au-delà de 9, le compte exact n'apprend plus rien et déforme la barre.
        pastille.textContent = n > 9 ? '9+' : String(n);
    };

    /* ---------- Entrées repliées, révélées à l'appui long ---------- */

    /*
     * Deux entrées de la barre en cachent une seconde : Compte cache la
     * déconnexion, Messages cache l'activité. La barre comptait sinon huit
     * cases, et sur mobile les libellés se chevauchaient.
     *
     * Le geste est délibéré — on ne reste pas appuyé par accident — ce qui
     * remplace avantageusement la confirmation qui gardait la déconnexion
     * trop accessible.
     */
    const DUREE = 500;      // ms avant révélation
    const TOLERANCE = 10;   // px de glissement admis

    function replier(conteneur) {
        const cachee = conteneur.querySelector('.nav-replie');
        const principale = conteneur.querySelector('.nav-principale');
        if (!cachee || !principale) return;

        let minuteur = null;
        let depart = null;
        let revele = false;
        let vientDeReveler = false;

        function montrer() {
            revele = true;
            vientDeReveler = true;
            cachee.hidden = false;
            conteneur.classList.add('est-ouvert');

            // Retour haptique là où il existe : l'appui long n'a aucun signal
            // visuel avant son terme.
            if (navigator.vibrate) navigator.vibrate(15);
        }

        function cacher() {
            revele = false;
            cachee.hidden = true;
            conteneur.classList.remove('est-ouvert');
        }

        function annuler() {
            clearTimeout(minuteur);
            minuteur = null;
            depart = null;
        }

        principale.addEventListener('pointerdown', (e) => {
            // Bouton droit : le menu contextuel s'en charge plus bas.
            if (e.button !== 0) return;

            depart = { x: e.clientX, y: e.clientY };
            minuteur = setTimeout(montrer, DUREE);
        });

        /*
         * Un glissement, c'est un défilement, pas un appui long. Sans cette
         * garde, faire défiler la page en partant de l'icône ouvrirait
         * l'entrée cachée.
         */
        principale.addEventListener('pointermove', (e) => {
            if (!depart) return;
            if (Math.abs(e.clientX - depart.x) > TOLERANCE
                || Math.abs(e.clientY - depart.y) > TOLERANCE) annuler();
        });

        ['pointerup', 'pointercancel', 'pointerleave'].forEach(evt =>
            principale.addEventListener(evt, annuler));

        // Clic droit sur ordinateur : même résultat, sans attendre.
        conteneur.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            revele ? cacher() : montrer();
        });

        /*
         * L'appui long ne doit pas ouvrir la page de l'entrée principale en se
         * relâchant.
         *
         * Écouté sur le document en capture, et non sur le lien : le routeur
         * pose son propre écouteur sur ce même lien, et comme il est chargé
         * avant, le sien s'exécuterait en premier — la page s'ouvrait malgré
         * la garde. `stopImmediatePropagation` coupe court avant lui.
         */
        document.addEventListener('click', (e) => {
            if (!vientDeReveler) return;
            if (!principale.contains(e.target)) return;

            e.preventDefault();
            e.stopImmediatePropagation();
            vientDeReveler = false;
        }, true);

        // Un geste ailleurs referme : laisser l'entrée ouverte sous le pouce
        // est exactement ce qu'on cherchait à éviter.
        document.addEventListener('pointerdown', (e) => {
            if (revele && !conteneur.contains(e.target)) cacher();
        }, true);
    }

    document.querySelectorAll('.nav-repli').forEach(replier);
})();
