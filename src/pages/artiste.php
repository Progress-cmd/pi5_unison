<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/config.php";
include_once "../includes/rendu.php";

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    echo '<p class="error">Artiste introuvable.</p>';
    exit;
}

$pdo = Config::getConnection();

$req = $pdo->prepare("SELECT id, name, img FROM artists WHERE id = :id");
$req->execute([':id' => $id]);
$artiste = $req->fetch(PDO::FETCH_ASSOC);
$defaultArtistImg = 'https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop';

if (!$artiste) {
    http_response_code(404);
    echo '<p class="error">Artiste introuvable.</p>';
    exit;
}

// Genres de l'artiste
$req = $pdo->prepare("
    SELECT genres.id, genres.name
    FROM genres
    JOIN artist__genre ON artist__genre.genre_id = genres.id
    WHERE artist__genre.artist_id = :id
    ORDER BY genres.name
");
$req->execute([':id' => $id]);
$genres = $req->fetchAll(PDO::FETCH_ASSOC);

// Écoutes totales sur tous les titres de l'artiste
$req = $pdo->prepare("
    SELECT COALESCE(SUM(nb_listen.nb), 0)
    FROM nb_listen
    JOIN artist__track ON artist__track.track_id = nb_listen.track_id
    WHERE artist__track.artist_id = :id
");
$req->execute([':id' => $id]);
$totalEcoutes = intval($req->fetchColumn());

// Titres de l'artiste (avec tous les artistes de chaque titre)
$req = $pdo->prepare("
    SELECT tracks.id, tracks.title, tracks.img,
           GROUP_CONCAT(DISTINCT a2.name SEPARATOR ', ') AS artists_names
    FROM tracks
    JOIN artist__track at1 ON at1.track_id = tracks.id AND at1.artist_id = :id
    LEFT JOIN artist__track at2 ON at2.track_id = tracks.id
    LEFT JOIN artists a2 ON a2.id = at2.artist_id
    GROUP BY tracks.id, tracks.title, tracks.img
    ORDER BY tracks.title
");
$req->execute([':id' => $id]);
$titres = $req->fetchAll(PDO::FETCH_ASSOC);

// Favori de l'utilisateur courant : l'état est rendu avec la page, pour que
// le cœur soit juste dès le premier affichage plutôt qu'après un aller-retour.
$req = $pdo->prepare("SELECT 1 FROM artist__favorite WHERE user_id = :user AND artist_id = :artiste");
$req->execute([':user' => (int) $_SESSION['user']['id'], ':artiste' => $id]);
$estFavori = (bool) $req->fetchColumn();
?>

<article id="artiste-detail" class="containers">
    <div class="head-bar"><?= htmlspecialchars($artiste['name'] ?? '') ?></div>
    <div class="body-bar">
        <div class="artiste-entete">
            <img src="<?= htmlspecialchars($artiste['img'] ?: $defaultArtistImg) ?>" class="artist-img" alt="Cover">
            <div class="artiste-infos">
                <div class="artiste-nom"><?= htmlspecialchars($artiste['name'] ?? '') ?></div>
                <?php if (!empty($genres)): ?>
                    <div class="artiste-genres">
                        <?php foreach ($genres as $genre): ?>
                            <span class="genre-badge"><?= htmlspecialchars($genre['name'] ?? '') ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="artiste-ecoutes">
                    <?php if ($totalEcoutes > 1) { echo $totalEcoutes.' écoutes'; } else { echo $totalEcoutes.' écoute'; } ?>
                    - <?php if (count($titres) > 1) { echo count($titres).' titres'; } else { echo count($titres).' titre'; } ?>
                </div>

                <div class="artiste-actions">
                    <?php if ($titres): ?>
                        <button type="button" class="buttons artiste-lire" id="artiste-lire">
                            <span class="material-symbols-outlined">play_arrow</span> Tout écouter
                        </button>
                    <?php endif; ?>
                    <button type="button" class="buttons artiste-favori<?= $estFavori ? ' actif' : '' ?>"
                            id="artiste-favori" data-favori="<?= $estFavori ? '1' : '0' ?>"
                            aria-pressed="<?= $estFavori ? 'true' : 'false' ?>"
                            title="<?= $estFavori ? 'Retirer des favoris' : 'Ajouter aux favoris' ?>">
                        <span class="material-symbols-outlined">favorite</span>
                    </button>
                </div>
            </div>
        </div>

        <h3>Titres</h3>
        <?php foreach ($titres as $titre): ?>
            <?= ligneTitre($titre, ['sous_titre' => $titre['artists_names'] ?: 'Artiste inconnu']) ?>
        <?php endforeach; ?>
        <?php if (!$titres): ?><?= ligneVide('Aucun titre pour cet artiste') ?><?php endif; ?>
    </div>
</article>

<script>
    (function () {
        const bloc = document.getElementById('artiste-detail');
        if (!bloc) return;
        const artisteId = <?= (int) $id ?>;

        // --- Tout écouter : remplace la file d'attente
        const lire = document.getElementById('artiste-lire');
        if (lire) {
            lire.addEventListener('click', async () => {
                try {
                    const res = await fetch('actions/artiste_lire.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'artist_id=' + artisteId,
                    });
                    const data = await res.json();

                    if (!data.success || !data.tracks || data.tracks.length === 0) {
                        window.showToast(data.message || 'Aucun titre', 'error');
                        return;
                    }

                    window.waitPlaylist = data.tracks;
                    window.sourcePlaylistId = null;
                    window.currentIndex = 0;
                    loadTrack(data.tracks[0].id, true);
                    window.showToast(data.tracks.length + ' titres en lecture', 'success');
                } catch (e) {
                    window.showToast('Erreur réseau', 'error');
                }
            });
        }

        // --- Favori
        const favori = document.getElementById('artiste-favori');
        favori.addEventListener('click', async () => {
            if (favori.disabled) return;
            favori.disabled = true;
            try {
                const res = await fetch('actions/toggle_favorite_artiste.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'artist_id=' + artisteId,
                });
                const data = await res.json();

                if (!data.success) {
                    window.showToast(data.message || 'Action impossible', 'error');
                    return;
                }

                // L'état vient du serveur, jamais d'une bascule locale : deux
                // onglets ouverts ne doivent pas diverger.
                favori.classList.toggle('actif', data.favori);
                favori.dataset.favori = data.favori ? '1' : '0';
                favori.setAttribute('aria-pressed', data.favori ? 'true' : 'false');
                favori.title = data.favori ? 'Retirer des favoris' : 'Ajouter aux favoris';
                window.showToast(data.favori ? 'Artiste ajouté aux favoris' : 'Retiré des favoris', 'success', 3000);
            } catch (e) {
                window.showToast('Erreur réseau', 'error');
            } finally {
                favori.disabled = false;
            }
        });
    })();
</script>

<style>
    #artiste-detail .artiste-entete {
        display: flex;
        gap: 20px;
        align-items: center;
        margin-bottom: 20px;
    }

    #artiste-detail .artist-img {
        width: 120px;
        height: 120px;
        object-fit: cover;
        border-radius: 50%;
    }

    #artiste-detail .artiste-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 12px;
    }

    #artiste-detail .artiste-lire {
        display: flex;
        align-items: center;
        gap: 6px;
        background-color: var(--text-orange);
        color: var(--white);
        border-color: var(--text-orange);
    }

    #artiste-detail .artiste-lire:hover {
        background-color: var(--text-orange-hover);
    }

    /* Le cœur est creux tant que l'artiste n'est pas en favori : c'est la
       graduation « FILL » de Material Symbols qui le remplit, pas une autre
       icône — la transition reste ainsi continue. */
    #artiste-detail .artiste-favori {
        color: var(--text-gray);
        transition: color 0.2s ease;
    }

    #artiste-detail .artiste-favori.actif {
        color: var(--text-orange);
        font-variation-settings: 'FILL' 1;
    }

    #artiste-detail .artiste-favori:disabled {
        opacity: 0.6;
        cursor: progress;
    }

    #artiste-detail .artiste-nom {
        font-family: var(--serif);
        font-size: 22px;
        margin-bottom: 8px;
    }

    #artiste-detail .artiste-genres {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 8px;
    }

    #artiste-detail .genre-badge {
        background: rgba(200, 93, 58, 0.1);
        border: 1px solid #C8593A;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        color: #C8593A;
    }

    #artiste-detail .artiste-ecoutes {
        color: #999;
        font-size: 13px;
    }

    #artiste-detail h3 {
        margin-top: 20px;
        margin-bottom: 15px;
        font-family: var(--serif);
        font-size: 18px;
    }
</style>
