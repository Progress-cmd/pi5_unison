<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/config.php";
$pdo = Config::getConnection();

/*
 * L'état « favori » remonte avec la liste.
 *
 * Il n'était visible que sur la fiche d'un artiste : depuis la vue d'ensemble
 * on ne pouvait ni savoir qui était en favori, ni en ajouter un — il fallait
 * ouvrir chaque fiche l'une après l'autre pour le découvrir.
 */
$req = $pdo->prepare("
            SELECT artists.id, artists.name, artists.img, COUNT(tracks.id) AS track_count,
                   EXISTS (SELECT 1 FROM artist__favorite
                            WHERE artist__favorite.artist_id = artists.id
                              AND artist__favorite.user_id = :user) AS favori
            FROM artists
            LEFT JOIN artist__track ON artist__track.artist_id = artists.id
            LEFT JOIN tracks ON tracks.id = artist__track.track_id
            GROUP BY artists.id, artists.name, artists.img
            ORDER BY track_count DESC, artists.name ASC
        ");
$req->bindValue(':user', (int) ($_SESSION['user']['id'] ?? 0), PDO::PARAM_INT);
$req->execute();

$listArtists = $req->fetchAll();
$defaultArtistImg = 'https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop';
?>
<article id="artist-list" class="containers">
    <div class="head-bar">Artistes</div>
    <div class="body-bar">
        <?php
        foreach ($listArtists as $artist) {
            $favori = (bool) $artist['favori'];
            $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            echo '<div class="mini-artist" data-artiste-id="'.(int) $artist['id'].'">
                      <div class="artist-vignette">
                          <img src="'.$e($artist['img'] ?: $defaultArtistImg).'" class="artist-img" alt="Cover">
                          <button type="button" class="artist-favori'.($favori ? ' actif' : '').'"
                                  data-artiste-id="'.(int) $artist['id'].'" data-favori="'.($favori ? '1' : '0').'"
                                  aria-pressed="'.($favori ? 'true' : 'false').'"
                                  title="'.($favori ? 'Retirer des favoris' : 'Ajouter aux favoris').'">
                              <span class="material-symbols-outlined">favorite</span>
                          </button>
                      </div>
                      <div class="artist-name">'.$e($artist['name']).'</div>
                  </div>';
        }
        ?>
    </div>
</article>

<script>
    (function() {
        const body = document.querySelector('#artist-list .body-bar');
        if (!body) return;

        body.addEventListener('click', async (e) => {
            /*
             * Le cœur est posé sur la vignette, donc à l'intérieur de la
             * carte : sans cette interception, chaque bascule ouvrait aussi la
             * fiche de l'artiste et le retour visuel était perdu aussitôt.
             */
            const coeur = e.target.closest('.artist-favori');
            if (coeur) {
                e.stopPropagation();
                await basculerFavori(coeur);
                return;
            }

            const card = e.target.closest('.mini-artist[data-artiste-id]');
            if (!card) return;
            sessionStorage.setItem('artiste_id', card.dataset.artisteId);
            navigateTo('library/artiste');
        });

        async function basculerFavori(bouton) {
            if (bouton.disabled) return;
            bouton.disabled = true;

            try {
                const res = await fetch('actions/toggle_favorite_artiste.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ artist_id: bouton.dataset.artisteId }),
                });
                const data = await res.json();

                if (!data.success) {
                    window.showToast(data.message || 'Opération impossible', 'error');
                    return;
                }

                bouton.classList.toggle('actif', data.favori);
                bouton.dataset.favori = data.favori ? '1' : '0';
                bouton.setAttribute('aria-pressed', data.favori ? 'true' : 'false');
                bouton.title = data.favori ? 'Retirer des favoris' : 'Ajouter aux favoris';
                window.showToast(data.favori ? 'Artiste ajouté aux favoris' : 'Artiste retiré des favoris');
            } catch (err) {
                window.showToast('Erreur réseau', 'error');
            } finally {
                bouton.disabled = false;
            }
        }
    })();
</script>
