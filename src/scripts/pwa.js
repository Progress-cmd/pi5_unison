/*
 * Installation de l'application et enregistrement du service worker.
 *
 * Le navigateur sait déjà proposer l'installation tout seul — une icône dans
 * la barre d'adresse sur ordinateur, « Ajouter à l'écran d'accueil » dans le
 * menu sur Android. Mais cette entrée est enfouie, et personne ne la cherche.
 * D'où le bouton de la page Paramètres, qui déclenche exactement la même
 * chose.
 */
(function () {
    'use strict';

    /*
     * L'événement arrive tôt, souvent avant que la page Paramètres existe. On
     * le retient : sans ça, le bouton n'aurait plus rien à déclencher au
     * moment où l'utilisateur l'atteint. Il n'est utilisable qu'une fois.
     */
    let invite = null;

    window.addEventListener('beforeinstallprompt', (e) => {
        // Sans ça, Chrome affiche sa propre bannière par-dessus l'application.
        e.preventDefault();
        invite = e;
        majBoutonInstallation();
    });

    window.addEventListener('appinstalled', () => {
        invite = null;
        majBoutonInstallation();
        if (window.showToast) window.showToast('Unison est installée');
    });

    /** L'application tourne-t-elle déjà dans sa propre fenêtre ? */
    function dejaInstallee() {
        return window.matchMedia('(display-mode: standalone)').matches
            // iOS ne connaît pas display-mode et passe par navigator.standalone.
            || window.navigator.standalone === true;
    }

    /*
     * État du bouton, relu à chaque fois que la page Paramètres s'affiche —
     * le routeur la réinjecte, l'élément n'est donc jamais le même.
     */
    window.majBoutonInstallation = function () {
        const bloc = document.getElementById('pwa-installation');
        if (!bloc) return;

        const bouton = bloc.querySelector('#pwa-installer');
        const note = bloc.querySelector('#pwa-note');

        if (dejaInstallee()) {
            bouton.hidden = true;
            note.textContent = "L'application est installée sur cet appareil.";
            return;
        }

        if (invite) {
            bouton.hidden = false;
            note.textContent = "Unison s'ouvrira dans sa propre fenêtre, sans barre d'adresse.";
            return;
        }

        /*
         * Pas d'invite : soit le navigateur ne la propose pas (Safari, Firefox),
         * soit la page n'est pas en contexte sécurisé. On distingue les deux —
         * dire « votre navigateur ne sait pas faire » quand le vrai problème
         * est une adresse en http:// enverrait chercher au mauvais endroit.
         */
        bouton.hidden = true;

        if (!window.isSecureContext) {
            note.textContent = "L'installation demande une adresse en https:// (ou localhost). "
                + "Cette page est ouverte en http://, le navigateur ne la proposera pas.";
            return;
        }

        /*
         * Firefox n'implémente pas beforeinstallprompt, et sur Android son
         * « Ajouter à l'écran d'accueil » pose un raccourci, pas une
         * application : pas de fenêtre propre, pas d'icône de lanceur. Le dire
         * franchement vaut mieux que laisser chercher un bouton qui
         * n'apparaîtra jamais.
         */
        if (/firefox|fxios/i.test(navigator.userAgent)) {
            note.textContent = "Firefox ne sait pas installer d'application web : son "
                + "« Ajouter à l'écran d'accueil » ne crée qu'un raccourci. "
                + "Pour une vraie installation, ouvrez Unison dans Chrome.";
            return;
        }

        if (/iphone|ipad|ipod/i.test(navigator.userAgent)) {
            note.textContent = "Sur iPhone : bouton Partager, puis « Sur l'écran d'accueil ».";
            return;
        }

        note.textContent = "Votre navigateur ne propose pas l'installation ici. "
            + "Si Unison est déjà installée, ouvrez-la depuis votre bureau.";
    };

    document.addEventListener('click', async (e) => {
        if (!e.target.closest('#pwa-installer') || !invite) return;

        const demande = invite;

        /*
         * try/catch, parce que prompt() échoue de plusieurs façons et que sans
         * lui l'échec était totalement muet : le bouton ne faisait
         * « absolument rien », sans message ni trace. Mesuré : un appel hors
         * geste utilisateur lève NotAllowedError, une invite déjà consommée
         * lève InvalidStateError, et les deux partaient en rejet non géré.
         */
        try {
            // Consommée seulement si l'appel aboutit : en cas d'échec on la
            // garde, pour que le second clic ait encore une chance.
            await demande.prompt();
            invite = null;

            const { outcome } = await demande.userChoice;
            if (outcome !== 'accepted' && window.showToast) {
                window.showToast('Installation annulée');
            }
        } catch (err) {
            invite = null;
            if (window.showToast) {
                window.showToast("Installation impossible : " + err.message, 'error', 6000);
            }
        }

        majBoutonInstallation();
    });

    /*
     * Le service worker n'est enregistré qu'après « load » : pendant le
     * premier chargement, il entrerait en concurrence avec les requêtes dont
     * la page a besoin pour s'afficher.
     */
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').catch((err) => {
                // Un échec ici n'empêche rien : l'application fonctionne sans.
                console.warn('Service worker non enregistré :', err.message);
            });
        });
    }
})();
