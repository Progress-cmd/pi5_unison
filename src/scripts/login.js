let currentUser = { name: 'Francis', initial: 'F' };

function selectUser(btn, name) {
    document.querySelectorAll('.login-user-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');

    document.getElementById('selectedUser').value = name;

    currentUser = { name, initial: name[0] };
}

function forgotPassword() {
    const selectedUser = document.getElementById('selectedUser').value;
    const token = document.querySelector('input[name="token"]').value;
    console.log('Utilisateur:', selectedUser);
    console.log('Token:', token);

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
        .then(response => {
            console.log('Status:', response.status);
            return response.text();  // Lire comme texte d'abord
        })
        .then(text => {
            console.log('Réponse brute:', text);  // Voir ce qu'on reçoit
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    alert('Email envoyé à ' + selectedUser);
                } else {
                    alert('Erreur: ' + data.message);
                }
            } catch (e) {
                console.error('Erreur JSON:', e);
                alert('Erreur serveur:\n' + text);
            }
        })
        .catch(error => {
            console.error('Erreur réseau:', error);
            alert('Erreur réseau');
        });
}