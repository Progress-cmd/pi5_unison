// `cle` est un identifiant d'affichage (« tortue », « papillon »), jamais un
// nom de compte : la correspondance se fait côté serveur.
function selectUser(btn, cle) {
    document.querySelectorAll('.login-user-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');

    document.getElementById('selectedUser').value = cle;
}

function forgotPassword() {
    const selectedUser = document.getElementById('selectedUser').value;
    const token = document.querySelector('input[name="token"]').value;

    fetch('actions/forgot_password.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            selectedUser: selectedUser,
            token: token
        })
    })
        .then(response => response.text())
        .then(text => {
            try {
                const data = JSON.parse(text);
                // Le message vient du serveur : il reste volontairement vague
                // pour ne pas dire si le compte existe.
                alert(data.message || (data.success ? 'Email envoyé' : 'Une erreur est survenue'));
            } catch (e) {
                alert('Une erreur est survenue, réessayez plus tard.');
            }
        })
        .catch(error => {
            console.error('Erreur réseau:', error);
            alert('Erreur réseau');
        });
}