let currentUser = { name: 'Francis', initial: 'F' };

function selectUser(btn, name) {
    document.querySelectorAll('.login-user-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');

    document.getElementById('selectedUser').value = name;

    currentUser = { name, initial: name[0] };
}

document.getElementById('login-card').addEventListener('submit', function(e) {
    e.preventDefault();
    const user = document.getElementById('selectedUser').value;
    console.log('Envoi:', user);
});

function forgotPassword() {
    // Récupérer l'utilisateur sélectionné
    const selectedUser = document.getElementById('selectedUser').value;
    const token = document.querySelector('input[name="token"]').value;

    console.log('Utilisateur:', selectedUser);
    console.log('Token:', token);

    // Envoyer une requête AJAX vers votre serveur
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
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Un email de réinitialisation a été envoyé à ' + selectedUser);
            } else {
                alert('Erreur: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Erreur:', error);
            alert('Une erreur est survenue');
        });
}