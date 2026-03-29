// Attendre que le DOM soit complètement chargé
document.addEventListener('DOMContentLoaded', function() {

    // Sélectionner le formulaire par son ID
    const form = document.getElementById('login-card');
    const password = document.getElementById('password');
    const passwordConfirm = document.getElementById('password-confirm');
    const errorMsg = document.getElementById('password-error');

    // Événement au submit du formulaire
    form.addEventListener('submit', function(e) {
        const pwd = password.value;
        const pwdConfirm = passwordConfirm.value;

        // Vérifier si les mots de passe sont identiques
        if (pwd !== pwdConfirm) {
            e.preventDefault(); // Empêcher l'envoi du formulaire
            errorMsg.style.display = 'block'; // Afficher l'erreur
            return false;
        }

        // Si OK, masquer le message d'erreur et envoyer le formulaire
        errorMsg.style.display = 'none';
        return true;
    });

    // Masquer le message quand les deux mots de passe correspondent
    password.addEventListener('input', checkPasswords);
    passwordConfirm.addEventListener('input', checkPasswords);

    function checkPasswords() {
        if (password.value === passwordConfirm.value && password.value !== '') {
            errorMsg.style.display = 'none';
        }
    }
});