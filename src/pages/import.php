<?php
include_once "../includes/auth.php";
exigerConnexion(false);
$lien = filter_input(INPUT_POST, 'url', FILTER_VALIDATE_URL);

if ($lien === null || $lien === false) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
    $token = $_SESSION['token'];
    ?>
    <form data-page="import" id="import-form" class="containers" method="post">
        <button class="material-symbols-outlined" type="submit">manage_search</button>
        <input type="url" name="url" placeholder="Lien Youtube" id="import-entry" required>

        <input type="hidden" name="token" value="<?= $token; ?>">
    </form>

    <article class="containers">
        <div class="body-bar">
            <div class="content">
                Importez un titre pour l'ajouter et ajuster ses informations,
                ou collez plusieurs liens ci-dessous pour un import en masse.
            </div>
        </div>
    </article>

    <!-- Import multiple : plusieurs liens ou une playlist YouTube -->
    <article class="containers" id="import-multiple">
        <div class="head-bar">Import multiple</div>
        <div class="body-bar">
            <textarea id="bulk-urls" placeholder="Collez un lien YouTube par ligne&#10;ou un lien de playlist à importer en entier..."></textarea>
            <div id="bulk-actions">
                <span id="bulk-hint">Playlists développées automatiquement</span>
                <button type="button" id="bulk-import-btn" class="buttons"
                        title="Ctrl + Entrée depuis la zone de saisie">Importer tout</button>
            </div>
            <div id="bulk-progress"></div>
        </div>
    </article>

    <script>
    // Connecteur vers le module global window.BulkImport (scripts/bulk-import.js).
    // L'orchestration vit hors de la page : l'import continue même si l'on
    // navigue ailleurs, et l'état est ré-affiché quand on revient ici.
    (function () {
        const btn = document.getElementById('bulk-import-btn');
        const textarea = document.getElementById('bulk-urls');
        const progress = document.getElementById('bulk-progress');
        if (!btn || !textarea || !window.BulkImport) return;

        function render(state) {
            progress.innerHTML = '';

            const echecs = state.items.filter(i => i.status === 'error');

            /*
             * Écran de confirmation : l'analyse est finie, rien n'est encore
             * téléchargé. Un lien de playlist collé par mégarde ne doit pas
             * partir chercher trente titres sans qu'on ait pu réagir.
             */
            if (state.enAttente) {
                const bloc = document.createElement('div');
                bloc.id = 'bulk-confirmation';

                const n = state.aConfirmer.length;
                const tete = document.createElement('div');
                tete.className = 'bulk-conf-tete';
                tete.innerHTML = '<span class="material-symbols-outlined">'
                               + (state.album ? 'album' : 'playlist_add_check') + '</span>';
                const libelle = document.createElement('b');

                // Un album est annoncé comme tel : c'est une autre décision que
                // d'importer une liste de titres sans lien entre eux.
                if (state.album) {
                    libelle.textContent = state.album.titre
                        + (state.album.artiste ? ' — ' + state.album.artiste : '');
                } else {
                    libelle.textContent = n + (n > 1 ? ' titres à importer' : ' titre à importer');
                }
                tete.appendChild(libelle);
                bloc.appendChild(tete);

                if (state.album) {
                    const sous = document.createElement('div');
                    sous.className = 'bulk-conf-sous';
                    sous.textContent = 'Album · ' + n + (n > 1 ? ' titres' : ' titre');
                    bloc.appendChild(sous);
                }

                const liste = document.createElement('div');
                liste.className = 'bulk-conf-liste';
                state.aConfirmer.forEach((t, i) => {
                    const l = document.createElement('div');
                    l.className = 'bulk-conf-ligne';
                    const num = document.createElement('span');
                    num.className = 'bulk-conf-num';
                    num.textContent = (i + 1) + '.';
                    const nom = document.createElement('span');
                    // textContent : ces titres viennent de YouTube.
                    nom.textContent = t.title;
                    l.append(num, nom);
                    liste.appendChild(l);
                });
                bloc.appendChild(liste);

                const actions = document.createElement('div');
                actions.className = 'bulk-conf-actions';

                const annuler = document.createElement('button');
                annuler.type = 'button';
                annuler.className = 'buttons';
                annuler.textContent = 'Annuler';
                annuler.addEventListener('click', () => window.BulkImport.annuler());

                const valider = document.createElement('button');
                valider.type = 'button';
                valider.className = 'buttons bulk-conf-valider';
                valider.textContent = state.album
                    ? "Importer l'album (" + n + ")"
                    : 'Télécharger ' + n + (n > 1 ? ' titres' : ' titre');
                valider.addEventListener('click', () => window.BulkImport.confirmer());

                actions.append(annuler, valider);
                bloc.appendChild(actions);
                progress.appendChild(bloc);
            }

            // Bilan des échecs en tête de liste : c'est ce qu'on doit voir en
            // premier en revenant sur la page, pas ce qu'on doit aller chercher
            // au milieu de cinquante lignes vertes.
            if (echecs.length && !state.running) {
                const bilan = document.createElement('div');
                bilan.id = 'bulk-echecs';
                bilan.innerHTML = `
                    <div class="bulk-echecs-tete">
                        <span class="material-symbols-outlined">error</span>
                        <b>${echecs.length} import(s) en échec</b>
                        <button type="button" id="copier-echecs" class="buttons">Copier les liens</button>
                    </div>`;

                echecs.forEach(it => {
                    const ligne = document.createElement('div');
                    ligne.className = 'bulk-echec-ligne';
                    ligne.innerHTML = '<div class="bulk-echec-titre"></div><div class="bulk-echec-raison"></div>';
                    ligne.querySelector('.bulk-echec-titre').textContent = it.title;
                    ligne.querySelector('.bulk-echec-raison').textContent = it.raison || 'Raison inconnue';
                    bilan.appendChild(ligne);
                });

                bilan.querySelector('#copier-echecs').addEventListener('click', () => {
                    const liens = echecs.map(i => i.url).filter(Boolean).join('\n');
                    navigator.clipboard.writeText(liens)
                        .then(() => window.showToast('Liens copiés', 'success', 3000))
                        .catch(() => window.showToast('Copie impossible', 'error'));
                });

                progress.appendChild(bilan);
            }

            state.items.forEach(it => {
                const div = document.createElement('div');
                div.className = 'bulk-item bulk-' + it.status;
                div.innerHTML = '<span class="bulk-status material-symbols-outlined"></span>'
                              + '<span class="bulk-textes"><span class="bulk-label"></span>'
                              + '<span class="bulk-raison"></span></span>';
                div.querySelector('.bulk-label').textContent = it.title;
                if (it.status === 'existant') {
                    div.querySelector('.bulk-raison').textContent = 'Déjà en base';
                }
                if (it.status === 'error') {
                    div.querySelector('.bulk-raison').textContent = it.raison || 'Raison inconnue';
                }
                progress.appendChild(div);
            });

            const traites = state.items.filter(i => i.status === 'done' || i.status === 'error').length;
            btn.disabled = state.running;
            btn.textContent = state.running
                ? (state.items.length ? `Import ${traites}/${state.items.length}` : 'Analyse...')
                : 'Importer tout';
        }

        // Ré-affiche l'état courant si un import tourne déjà (retour sur la page)
        render(window.BulkImport.state);

        // Un seul écouteur, même si la page est réinjectée plusieurs fois
        if (window._bulkPageHandler) {
            window.removeEventListener('bulkimport:update', window._bulkPageHandler);
        }
        window._bulkPageHandler = (e) => render(e.detail);
        window.addEventListener('bulkimport:update', window._bulkPageHandler);

        btn.addEventListener('click', () => window.BulkImport.start(textarea.value));

        /*
         * Ctrl + Entrée (Cmd sur Mac) lance l'import depuis la zone de saisie,
         * sans aller chercher le bouton à la souris — on vient justement d'y
         * coller ses liens.
         *
         * Entrée seule reste un retour à la ligne : la zone accepte un lien
         * par ligne, la détourner rendrait la saisie multiple impossible.
         *
         * Le raccourci passe par le bouton plutôt que d'appeler BulkImport
         * directement : il hérite ainsi de son état désactivé, et ne peut pas
         * relancer un import déjà en cours.
         */
        textarea.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' || !(e.ctrlKey || e.metaKey)) return;

            e.preventDefault();
            if (!btn.disabled) btn.click();
        });
    })();
    </script>
<?php } else {
    require_once "../includes/ytImport.php";

    /*
     * Refus des liens de playlist, AVANT toute vérification de jeton.
     *
     * Ce contrôle était placé plus bas, après la consommation du jeton à usage
     * unique. Le message s'affichait donc une fois, puis le formulaire de la
     * page — resté à l'écran avec son ancien jeton — se faisait refuser en
     * « Token invalide » à chaque tentative suivante : plus aucune explication,
     * l'utilisateur ne comprenait pas pourquoi son lien ne donnait plus rien.
     *
     * Rien n'est consommé ici : refuser un lien n'est pas une action, et
     * recoller le même lien doit redonner le même message.
     */
    if (lienEstPlaylist($lien)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => "Ceci est un lien de playlist ou d'album. "
                       . "Collez-le dans « Import multiple » juste en dessous : "
                       . "il sera développé en titres, et vous validerez avant téléchargement.",
        ]);
        exit;
    }

    if (
            !isset($_POST['token'], $_SESSION['token']) ||
            $_POST['token'] !== $_SESSION['token']
    ) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Token invalide']);
        exit;
    }

    unset($_SESSION['token']);
    $_SESSION['token'] = bin2hex(random_bytes(32));
    $token = $_SESSION['token'];

    /*
     * Les métadonnées passent par extractYtMetadata(), comme l'import en masse.
     *
     * Cette page en réimplémentait une copie : même déduction « Artiste -
     * Titre », même nettoyage des « (Official Video) », même repli sur le nom
     * de chaîne. Les deux versions avaient commencé à diverger — celle-ci
     * lisait « thumbnails[count(thumbnails)-1] », qui déclenche un
     * avertissement dès que yt-dlp n'en renvoie aucune, et repartait sans
     * pochette. Elle appelait en plus shell_exec(), qui jette la sortie
     * d'erreur : un échec de yt-dlp devenait « Lien invalide », sans jamais
     * dire pourquoi.
     */
    require_once "../includes/ytImport.php";
    include_once "../includes/config.php";

    $raison = null;
    $meta = extractYtMetadata($lien, $raison);

    if ($meta === null) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $raison ?: 'Lien invalide']);
        exit;
    }

    $title    = $meta['title'];
    $artist   = $meta['artist'];
    $duration = $meta['duration'];
    $thumb    = $meta['miniature'];
    $genre    = $meta['genre'];

    $pdo = Config::getConnection();

    /*
     * L'identité d'un titre, c'est la vidéo — pas son intitulé.
     *
     * Ce contrôle comparait « title = :title », si bien que deux
     * enregistrements différents portant le même nom se bloquaient l'un
     * l'autre : « Somewhere Only We Know » de Keane interdisait la reprise de
     * Lily Allen. L'import réel, lui, a toujours comparé l'URL et le fichier
     * (voir importTrackFromUrl) : les deux contrôles disaient des choses
     * différentes, et c'est le plus grossier qui décidait.
     */
    $urlSoumise = filter_input(INPUT_POST, 'url', FILTER_VALIDATE_URL);
    parse_str((string) parse_url((string) $urlSoumise, PHP_URL_QUERY), $parametresUrl);
    $videoId = $parametresUrl['v'] ?? basename((string) parse_url((string) $urlSoumise, PHP_URL_PATH));

    // Le nom du fichier est « <video_id>.<extension> », l'extension dépendant
    // de l'époque de l'import (m4a aujourd'hui, wav autrefois). Les jokers de
    // LIKE sont échappés : un identifiant YouTube contient souvent « _ ».
    $motifFichier = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $videoId) . '.%';

    $req = $pdo->prepare(
        "SELECT id, title FROM tracks
         WHERE url = :url OR file LIKE :motif ESCAPE '\\\\'
         LIMIT 1"
    );
    $req->execute([':url' => $urlSoumise, ':motif' => $motifFichier]);
    $dejaImporte = $req->fetch(PDO::FETCH_ASSOC);

    /*
     * Titre déjà présent, venu d'un album : le réimport manuel le rend
     * indépendant de cet album.
     *
     * C'est une prise de possession — « ce titre m'intéresse pour lui-même,
     * pas parce qu'il venait d'un album ». Il reste affiché dans l'album,
     * mais ne sera plus supprimé avec lui.
     *
     * Rien n'est retéléchargé : le fichier est déjà sur le disque.
     */
    $detacheDe = null;
    if ($dejaImporte) {
        require_once "../includes/albums.php";
        $detacheDe = albumDetacherTitre($pdo, (int) $dejaImporte['id']);
    }

    /*
     * Même titre, même artiste, mais une autre vidéo : ce n'est pas forcément
     * un doublon (version live, remasterisée…). On le signale sans interdire.
     */
    $req = $pdo->prepare("
        SELECT tracks.id FROM tracks
        JOIN artist__track ON artist__track.track_id = tracks.id
        JOIN artists ON artists.id = artist__track.artist_id
        WHERE tracks.title = :titre AND artists.name = :artiste
        LIMIT 1
    ");
    $req->execute([':titre' => $title, ':artiste' => $artist]);
    $memeTitreMemeArtiste = (bool) $req->fetch();

    if (!$dejaImporte) {
        ?>
        <form data-action="../actions/import.php" id="import-check" class="containers" method="post">
            <?php if ($memeTitreMemeArtiste): ?>
                <p class="import-note">
                    Un titre du même nom et du même artiste est déjà présent.
                    Ce n'est pas forcément un doublon — vous pouvez importer quand même.
                </p>
            <?php endif; ?>
            <label>
                <input type="text" class="alterable" value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>" name="title" readonly required>
            </label>

            <img src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>" alt="image">

            <label>Artiste :
                <input type="text" class="alterable" value="<?= htmlspecialchars($artist, ENT_QUOTES, 'UTF-8') ?>" name="artist" readonly required>
            </label>
            <br>

            <label>Genre :
                <input type="text" class="alterable" value="<?= htmlspecialchars($genre, ENT_QUOTES, 'UTF-8') ?>" name="genre" placeholder="Genre inconnu" readonly>
            </label>
            <br>

            <label>Durée :
                <input type="text" value="<?= htmlspecialchars($duration, ENT_QUOTES, 'UTF-8') ?>" name="duration" readonly>
            </label>
            <br>

            <input type="hidden" value="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>" name="miniature">
            <input type="hidden" value="<?php echo filter_input(INPUT_POST, 'url', FILTER_VALIDATE_URL); ?>" name="url">
            <input type="hidden" name="token" value="<?= $token; ?>">

            <div id="import-section_buttons">
                <label>Des modifications ? :
                    <input type="checkbox" id="edit-toggle">
                </label>
                <button type="submit" class="buttons">Charger</button>
            </div>
        </form>
    <?php } else {
        ?>
        <article class="containers">
            <div class="body-bar">
                <div class="content">
                    <?php if ($detacheDe): ?>
                        <em><?= htmlspecialchars($dejaImporte['title'] ?? $title, ENT_QUOTES, 'UTF-8') ?></em>
                        était déjà présent, venu de l'album
                        <em><?= htmlspecialchars($detacheDe['title'], ENT_QUOTES, 'UTF-8') ?></em>.<br>
                        Il en est maintenant indépendant : il y reste affiché, mais ne sera plus
                        supprimé avec lui.
                    <?php else: ?>
                        <em><?= htmlspecialchars($dejaImporte['title'] ?? $title, ENT_QUOTES, 'UTF-8') ?></em>&nbsp;
                        a déjà été importé depuis cette même vidéo.
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php
    }
} ?>

<script>
    (function () {
        const toggle = document.getElementById('edit-toggle');

        // Vérifie que la checkbox existe avant d'attacher l'événement
        if (!toggle) return;

        toggle.addEventListener('change', function() {
            document.querySelectorAll('.alterable').forEach(input => {
                if (this.checked) {
                    input.removeAttribute('readonly');
                    input.focus();
                } else {
                    input.setAttribute('readonly', true);
                }
            });
        });
    })();
</script>

<!--
echo json_encode(['success' => true, 'message' => 'Importé avec succès']);
// ou en cas d'erreur :
echo json_encode(['success' => false, 'message' => "Erreur lors de l'import"]);
-->