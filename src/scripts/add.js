(function() {
    const mainContent = document.getElementById('main-content') || document.body;
    let currentToken = null;

    async function getToken() {
        if (currentToken) return currentToken; // réutilise si déjà récupéré
        const res = await fetch('actions/token.php');
        const data = await res.json();
        currentToken = data.token;
        return currentToken;
    }

    async function bindAddForm() {
        const searchForm = mainContent.querySelector('.add-form:not(#verif-form)');
        if (searchForm) {
            // Injecte le token dès l'affichage du form
            const tokenInput = searchForm.querySelector('#csrf-token');
            if (tokenInput) tokenInput.value = await getToken();

            searchForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(searchForm);

                const res = await fetch('pages/add.php', {
                    method: 'POST',
                    body: formData
                });

                mainContent.innerHTML = await res.text();
                bindAddForm();
            });
        }

        const verifForm = mainContent.querySelector('#verif-form');
        if (verifForm) {
            const tokenInput = verifForm.querySelector('#csrf-token');
            if (tokenInput) tokenInput.value = await getToken();

            verifForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(verifForm);

                console.log('verifForm submit, entries:', [...formData.entries()]);

                const res = await fetch('actions/add.php', {
                    method: 'POST',
                    body: formData
                });

                console.log('response status:', res.status);
                const text = await res.text();
                console.log('response text:', text);

                mainContent.innerHTML = text;
            });
        }
    }

    bindAddForm();
})();