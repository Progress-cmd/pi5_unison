const input = document.getElementById('search-entry');
const resultsDiv = document.getElementById('search-results');

// Recherche en live (au fur et à mesure de la frappe)
input.addEventListener('input', async () => {
    const query = input.value.trim();

    if (query.length < 2) {
        resultsDiv.innerHTML = '';
        return;
    }

    const formData = new FormData();
    formData.append('search-entry', query);

    const response = await fetch('../actions/search.php', {
        method: 'POST',
        body: formData
    });

    const hits = await response.json();
    afficherResultats(hits);
});

function afficherResultats(hits) {
    if (hits.length === 0) {
        resultsDiv.innerHTML = '<p>Aucun résultat</p>';
        return;
    }

    resultsDiv.innerHTML = hits.map(hit => `
        <div class="result-item">
            <span>${hit.title}</span>
        </div>
    `).join('');
}