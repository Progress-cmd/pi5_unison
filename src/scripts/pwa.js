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
        note.textContent = window.isSecureContext
            ? "Sur iPhone : bouton Partager, puis « Sur l'écran d'accueil ». "
              + "Sur Firefox : menu ⋮, puis « Installer »."
            : "L'installation demande une adresse en https:// (ou localhost). "
              + "Cette page est ouverte en http://, le navigateur ne la proposera pas.";
    };

    document.addEventListener('click', async (e) => {
        if (!e.target.closest('#pwa-installer') || !invite) return;

        const demande = invite;
        // Remis à zéro tout de suite : une invite ne se rejoue pas, et un
        // second clic sur un objet consommé lève une exception.
        invite = null;

        demande.prompt();
        const { outcome } = await demande.userChoice;
        if (outcome !== 'accepted' && window.showToast) {
            window.showToast('Installation annulée');
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
