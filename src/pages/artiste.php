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
            </div>
        </div>

        <h3>Titres</h3>
        <?php foreach ($titres as $titre): ?>
            <?= ligneTitre($titre, ['sous_titre' => $titre['artists_names'] ?: 'Artiste inconnu']) ?>
        <?php endforeach; ?>
        <?php if (!$titres): ?><?= ligneVide('Aucun titre pour cet artiste') ?><?php endif; ?>
    </div>
</article>

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
