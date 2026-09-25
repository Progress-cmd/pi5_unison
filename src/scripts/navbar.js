/**
 * Barre de navigation : pastille des messages non lus.
 *
 * Vit hors des pages, parce que la barre survit à la navigation du routeur.
 */
(function () {
    const pastille = document.getElementById('nav-messages-pastille');
    if (!pastille) return;

    /*
     * Appelée par le battement de présence (scripts/presence.js), qui rapporte
     * les non-lus, et par le chat lui-même quand il vient de tout lire.
     */
    window.majPastilleMessages = function (nombre) {
        const n = Number(nombre) || 0;
        pastille.hidden = n === 0;
        // Au-delà de 9, le compte exact n'apprend plus rien et déforme la barre.
        pastille.textContent = n > 9 ? '9+' : String(n);
    };
})();
