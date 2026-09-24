/**
 * Réglages propres à l'appareil, et application du thème.
 *
 * Ce qui vit ici ne suit pas la personne d'une machine à l'autre : le volume
 * du téléphone n'a rien à voir avec celui du PC, et « reprendre où je m'étais
 * arrêté » n'a de sens que là où on s'est arrêté. Les préférences qui suivent
 * la personne (présence, thème, nom) sont en base — voir actions/preferences.php.
 *
 * Chargé avant player.js, qui lit ces valeurs au démarrage.
 */
(function () {
    const PREFIXE = 'unison.pref.';

    /*
     * localStorage lève dans une fenêtre privée, avec le stockage bloqué, ou
     * quand le quota est plein. Rien ici n'est essentiel : en cas d'échec on
     * retombe sur la valeur par défaut plutôt que de casser la page.
     */
    function brut(cle) {
        try {
            return localStorage.getItem(PREFIXE + cle);
        } catch (e) {
            return null;
        }
    }

    function poser(cle, valeur) {
        try {
            localStorage.setItem(PREFIXE + cle, valeur);
            return true;
        } catch (e) {
            return false;
        }
    }

    // Réglages booléens, avec leur valeur par défaut.
    const DEFAUTS = {
        memoriserVolume: true,
        reprise:         true,
        aleatoire:       false,
    };

    window.unisonPrefs = {
        /** Un réglage booléen, ou son défaut si rien n'est stocké. */
        lire(cle) {
            const v = brut(cle);
            if (v === null) return DEFAUTS[cle] ?? false;
            return v === '1';
        },

        ecrire(cle, actif) {
            return poser(cle, actif ? '1' : '0');
        },

        /** Le dernier volume réglé, entre 0 et 1, ou null si non mémorisé. */
        volume() {
            const v = parseFloat(brut('volume'));
            return Number.isFinite(v) && v >= 0 && v <= 1 ? v : null;
        },

        poserVolume(v) {
            if (Number.isFinite(v)) poser('volume', String(v));
        },

        /** Dernière écoute : { id, position } — pour la reprise. */
        derniereEcoute() {
            try {
                const o = JSON.parse(brut('derniereEcoute') || 'null');
                return o && Number.isFinite(o.id) ? o : null;
            } catch (e) {
                return null;
            }
        },

        poserDerniereEcoute(id, position) {
            poser('derniereEcoute', JSON.stringify({
                id: Number(id),
                position: Math.max(0, Math.floor(position || 0)),
            }));
        },
    };

    /*
     * Thème : l'attribut est posé côté serveur sur <html> pour éviter le
     * clignotement au chargement. Cette fonction ne sert qu'au changement à
     * chaud depuis la page Paramètres.
     */
    window.appliquerTheme = function (theme) {
        if (['clair', 'sombre', 'systeme'].indexOf(theme) === -1) return;
        document.documentElement.setAttribute('data-theme', theme);
    };

    /*
     * Nom affiché : l'en-tête vit hors de #main-content, le routeur ne le
     * redessine pas. Appelé après un changement de nom réussi.
     */
    window.majNomAffiche = function (nom) {
        const titre = document.querySelector('#headline em');
        if (titre) titre.textContent = nom;

        const initiale = document.querySelector('.first-person .cercle-initiale');
        if (initiale) initiale.textContent = (nom || '').trim().charAt(0).toUpperCase();
    };
})();
