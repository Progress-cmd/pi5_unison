/**
 * Utilitaires de la section d'administration.
 *
 * Les actions de cette section sont destructrices et portent sur le contenu de
 * tous les comptes : chaque appel passe donc par une confirmation, et le jeton
 * CSRF est systématiquement joint. Le contrôle réel reste côté serveur
 * (exigerAdmin + verifierCsrf), ce script n'est qu'un garde-fou d'usage.
 */
(function () {
    // Le routeur réinjecte ce script à chaque navigation : on ne redéclare pas.
    if (window.AdminUnison) return;

    /** Jeton CSRF, déposé par les pages admin dans un attribut de données. */
    function jeton() {
        const el = document.querySelector('[data-csrf]');
        return el ? el.dataset.csrf : '';
    }

    /**
     * Appelle une action d'administration.
     * Renvoie l'objet de réponse, ou null si l'appel a échoué.
     */
    async function appeler(action, donnees = {}) {
        const corps = new URLSearchParams({ ...donnees, token: jeton() });

        try {
            const res = await fetch('actions/admin/' + action, { method: 'POST', body: corps });

            // 404 = exigerAdmin() a refusé : la session n'est plus admin.
            if (res.status === 404) {
                window.showToast && window.showToast(
                    'Session non administratrice — reconnectez-vous', 'error', 0);
                return null;
            }

            const data = await res.json();
            if (data.message) {
                window.showToast && window.showToast(data.message, data.success ? 'success' : 'error',
                    data.success ? 4000 : 0);
            }
            return data;
        } catch (e) {
            window.showToast && window.showToast('Erreur réseau', 'error', 0);
            return null;
        }
    }

    /**
     * Confirmation en deux temps pour les opérations irréversibles :
     * il faut retaper le nom exact de ce qu'on supprime.
     */
    function confirmerParNom(nom, quoi) {
        const saisie = prompt(
            `Suppression définitive de ${quoi} :\n\n« ${nom} »\n\n` +
            `Cette action est irréversible. Retapez le nom exact pour confirmer :`
        );
        if (saisie === null) return false;

        if (saisie.trim() !== nom) {
            window.showToast && window.showToast('Le nom saisi ne correspond pas — rien n\'a été supprimé', 'error');
            return false;
        }
        return true;
    }

    /** Filtre client sur un tableau : masque les lignes sans correspondance. */
    function brancherFiltre(idChamp, idTable) {
        const champ = document.getElementById(idChamp);
        const table = document.getElementById(idTable);
        if (!champ || !table) return;

        champ.addEventListener('input', () => {
            const q = champ.value.trim().toLowerCase();
            table.querySelectorAll('tbody tr').forEach(tr => {
                tr.hidden = q !== '' && !tr.textContent.toLowerCase().includes(q);
            });
        });
    }

    window.AdminUnison = { appeler, confirmerParNom, brancherFiltre };
})();
