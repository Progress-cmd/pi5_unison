<?php
// Vérification de la connexion utilisateur
include_once "includes/auth.php";
demarrerSession();

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$demo = estDemo();

/*
 * Le compte d'administration a sa propre interface : ni player, ni file
 * d'attente, ni pages d'écoute. index.php reste le squelette commun, mais
 * n'en rend que la partie utile — le reste n'est pas masqué en CSS, il n'est
 * tout simplement pas envoyé.
 */
$admin = estAdmin();

/*
 * Le thème est posé sur <html> côté serveur, et non par un script : appliqué
 * après coup, la page s'afficherait d'abord en clair avant de basculer, ce qui
 * se voit — surtout le soir, précisément quand on a choisi le mode sombre.
 */
$theme = $_SESSION['user']['theme'] ?? 'systeme';
if (!in_array($theme, ['clair', 'sombre', 'systeme'], true)) {
    $theme = 'systeme';
}
?>
<!doctype html>
<html lang="fr" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= assetVersionne('styles/style.css') ?>"> <!-- Feuille de style principale -->
    <?php if ($admin): ?>
    <!-- Chargée dès l'ossature : l'en-tête d'administration en dépend, sans
         attendre que le routeur injecte une page de gestion. -->
    <link rel="stylesheet" href="<?= assetVersionne('styles/admin.css') ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"> <!-- Intégration des différents icons -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500;600&display=swap"> <!-- Ajout de deux polices d'écritures -->
    <title>Unison</title>

    <?php
    /*
     * Chargé dans l'en-tête, avant tout le reste : il enrobe fetch() pour
     * poser le jeton anti-CSRF sur les écritures, et doit donc être en place
     * avant le premier appel de n'importe quel script.
     */
    ?>
    <script>window.UNISON_CSRF = <?= json_encode(jetonCsrf()) ?>;</script>
    <script src="<?= assetVersionne('scripts/csrf.js') ?>"></script>

    <?php
    /*
     * Réglages propres à l'appareil (volume mémorisé, reprise) : chargé avant
     * player.js, qui les lit à son démarrage.
     */
    ?>
    <script src="<?= assetVersionne('scripts/prefs.js') ?>"></script>

    <?php
    /*
     * Mode de notification du compte, lu par scripts/presence.js. Rendu ici
     * plutôt que demandé au serveur : l'information tient en un mot et le
     * battement a déjà de quoi faire.
     */
    ?>
    <script>window.UNISON_NOTIF = <?= json_encode($_SESSION['user']['notif_mode'] ?? 'toast') ?>;</script>
    <?php
    /*
     * Le partenaire, pour le menu contextuel des titres (« Envoyer à … »).
     * Défini plus bas dans la page pour l'en-tête ; recalculé ici parce que
     * ce bloc est servi avant.
     */
    $pourEnvoi = idPartenaire();
    ?>
    <?php if ($pourEnvoi !== null): ?>
    <script>window.UNISON_PARTENAIRE = <?= json_encode([
        'id'  => $pourEnvoi,
        'nom' => nomMembre($pourEnvoi),
    ]) ?>;</script>
    <?php endif; ?>
</head>
<body class="<?= $demo ? 'is-demo' : '' ?>">
    <?php if ($demo): ?>
    <!-- Bandeau permanent : rappelle que la session est en lecture seule -->
    <div id="demo-banner">
        <span class="material-symbols-outlined">visibility</span>
        <span id="demo-banner-text">
            <b>Mode démonstration</b> — vous explorez Unison en lecture seule.
        </span>
        <a href="actions/logout.php" id="demo-banner-exit">Quitter</a>
    </div>
    <?php endif; ?>

    <!-- La politesse avant tout -->
    <header>
        <?php
        $time = date("H");
        $salutation = ($time <= 17) ? "Bonjour" : "Bonsoir";
        if ($time >= 7 && $time < 12) {
            $moment = "ce matin";
        }
        else if ($time >= 12 && $time < 14) {
            $moment = "ce midi";
        }
        else if ($time >= 14 && $time < 18) {
            $moment = "cette après-midi";
        }
        else if ($time >= 18 && $time < 22) {
            $moment = "ce soir";
        }
        else {
            $moment = "cette nuit";
        }
        ?>
        <div id="headline"><?= $salutation ?>,<br>
            <em><?= htmlspecialchars($_SESSION['user']['username'], ENT_QUOTES) ?></em>
            <p id="headline-sub">
                <?= $admin ? 'Console d\'administration' : 'Que voulez-vous écouter ' . $moment . ' ?' ?>
            </p>
        </div>
        <?php
        $isPersonal = (($_SESSION['user']['view_mode'] ?? 'mixed') === 'personal');
        $partenaire = idPartenaire();
        ?>
        <?php
        /*
         * Les deux cercles servent aussi de bascule d'affichage : les deux
         * allumés = contenu commun, seul le mien = contenu perso. Le bloc n'est
         * rendu que pour les membres du foyer ; hors foyer la bascule n'a pas
         * de sens. Commentaire PHP et non HTML : il ne doit pas être servi.
         */
        ?>
        <?php if ($admin): ?>
        <?php
        /*
         * La déconnexion vit normalement dans la page Compte, à laquelle un
         * compte d'administration n'a plus accès : sans ce lien, il serait
         * impossible d'en sortir autrement qu'en supprimant le cookie.
         */
        ?>
        <a href="actions/logout.php" id="admin-quitter" title="Se déconnecter">
            <span class="material-symbols-outlined">logout</span>
            Quitter
        </a>
        <?php endif; ?>

        <?php if ($partenaire !== null): ?>
        <section id="persons" class="<?= $isPersonal ? 'is-personal' : 'is-mixed' ?>"
                 role="switch" aria-checked="<?= $isPersonal ? 'true' : 'false' ?>"
                 title="Afficher le contenu commun ou seulement le mien">
            <div class="first-person user-<?= (int) $_SESSION['user']['id'] ?>">
                <span class="cercle-initiale"><?= htmlspecialchars(initialeMembre((int) $_SESSION['user']['id']), ENT_QUOTES) ?></span>
            </div>
            <?php
            /*
             * L'initiale dit qui ; la pastille en bas à droite dit dans quel
             * état. Les deux coexistent — remplacer l'initiale par l'état
             * faisait perdre le « qui » au moment précis où l'autre devient
             * intéressant. Pastilles remplies par scripts/presence.js ;
             * aucune visible = hors ligne, ou présence indisponible.
             */
            ?>
            <div class="second-person user-<?= $partenaire ?>"
                 id="presence-partenaire" data-user-id="<?= (int) $partenaire ?>">
                <span class="cercle-initiale"><?= htmlspecialchars(initialeMembre((int) $partenaire), ENT_QUOTES) ?></span>
                <span class="presence-point" hidden></span>
                <span class="presence-vague" hidden><i></i><i></i><i></i></span>
            </div>
        </section>
        <?php endif; ?>
    </header>

    <div id="toast-container"></div>

    <?php if (!$admin): ?>
    <!-- Indicateur d'import en arrière-plan (persiste entre les pages) -->
    <div id="import-indicator" title="Voir l'import en cours">
        <span class="imp-spinner material-symbols-outlined">progress_activity</span>
        <div class="imp-info">
            <div class="imp-head"><span class="imp-label">Import</span><span class="imp-count"></span></div>
            <div class="imp-title"></div>
            <div class="imp-bar"><div class="imp-bar-fill"></div></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Le contenu non statique de la page -->
    <div id="content-row">
        <main id="main-content"></main>
        <?php if (!$admin): ?>
        <!-- Colonne du player sur bureau (remplie par router.js, sauf sur l'accueil) -->
        <aside id="player-aside"></aside>
        <?php endif; ?>
    </div>

    <footer>
        <?php if (!$admin): ?>
        <!-- Le lecteur audio -->
        <section id="player">
            <!-- PLAYER RETRACTED -->
            <div id="retract">
                <div class="player-controls">
                    <button class="buttons material-symbols-outlined play-button">
                        play_arrow
                    </button>
                </div>

                <div class="player-infos">
                    <div class="infos title-info">Loading ...</div>
                    <div class="infos artist-info">Loading ...</div>
                </div>

                <div class="player-controls">
                    <button class="buttons material-symbols-outlined favorite-button">
                        favorite
                    </button>
                    <button class="buttons material-symbols-outlined next-button">
                        skip_next
                    </button>
                </div>

                <div class="player-progress_bar">
                    <div class="player-progress_current"></div>
                </div>
            </div>

            <!-- PLAYER EXTENDED (FULL SCREEN) -->
            <div id="extend">
                <div class="player-controls">
                    <button class="buttons material-symbols-outlined" id="close-button">
                        arrow_forward_ios
                    </button>
                    <button class="buttons material-symbols-outlined" id="more-button">
                        more_vert
                    </button>
                </div>

                <img src="https://images.unsplash.com/photo-1506157786151-b8491531f063?q=80&w=300&auto=format&fit=crop" alt="" id="player-img">

                <div class="player-controls">
                    <button class="buttons material-symbols-outlined" id="add-button">
                        add
                    </button>
                    <button class="buttons material-symbols-outlined favorite-button">
                        favorite
                    </button>
                </div>

                <div class="player-progress">
                    <div class="player-time">
                        <span class="time-current">0:00</span>
                        <span class="time-total">10:00</span>
                    </div>
                    <div class="player-progress_bar">
                        <div class="player-progress_current"></div>
                    </div>
                </div>

                <div class="player-infos">
                    <div class="infos title-info">Loading ...</div>
                    <div class="infos artist-info">Loading ...</div>
                </div>

                <div class="player-controls">
                    <button class="buttons material-symbols-outlined" id="repeat-button">
                        repeat
                    </button>
                    <button class="buttons material-symbols-outlined prev-button">
                        skip_previous
                    </button>
                    <button class="buttons material-symbols-outlined play-button">
                        play_arrow
                    </button>
                    <button class="buttons material-symbols-outlined next-button">
                        skip_next
                    </button>
                    <button class="buttons material-symbols-outlined" id="rand-button">
                        shuffle
                    </button>
                </div>

                <div class="player-controls">
                    <a href="#" class="buttons material-symbols-outlined" id="queue-button" data-page="player/queue">
                        queue_music
                    </a>
                    <button class="buttons material-symbols-outlined" id="volume-button">
                        volume_up <!--volume_down volume_off -->
                    </button>
                    <button class="buttons material-symbols-outlined" id="menu-button">
                        instant_mix
                    </button>
                </div>
            </div>
        </section>

        <script src="<?= assetVersionne('scripts/ligneTitre.js') ?>"></script>
        <script src="<?= assetVersionne('scripts/player.js') ?>"></script>
        <script src="<?= assetVersionne('scripts/track-context-menu.js') ?>"></script>
        <script src="<?= assetVersionne('scripts/playlist-editor.js') ?>"></script>
        <?php endif; ?>

        <!-- Le menu de navigation -->
        <nav id="navbar">
            <!-- Logo affiché uniquement sur la version bureau (voir style.css) -->
            <div id="nav-brand">Unison</div>

            <?php if ($admin): ?>
            <a href="?page=admin" data-page="admin">
                <div class="icons material-symbols-outlined">monitoring</div>
                Tableau
            </a>
            <a href="?page=admin/contenu" data-page="admin/contenu">
                <div class="icons material-symbols-outlined">library_music</div>
                Contenu
            </a>
            <a href="?page=admin/stockage" data-page="admin/stockage">
                <div class="icons material-symbols-outlined">hard_drive</div>
                Stockage
            </a>
            <a href="?page=admin/comptes" data-page="admin/comptes">
                <div class="icons material-symbols-outlined">group</div>
                Comptes
            </a>
            <a href="?page=admin/maintenance" data-page="admin/maintenance">
                <div class="icons material-symbols-outlined">build</div>
                Maintenance
            </a>
            <a href="?page=admin/annonces" data-page="admin/annonces">
                <div class="icons material-symbols-outlined">campaign</div>
                Annonces
            </a>
            <a href="?page=admin/journal" data-page="admin/journal">
                <div class="icons material-symbols-outlined">receipt_long</div>
                Journal
            </a>
            <a href="?page=admin/console" data-page="admin/console">
                <div class="icons material-symbols-outlined">terminal</div>
                Console
            </a>
            <a href="?page=admin/sql" data-page="admin/sql">
                <div class="icons material-symbols-outlined">database</div>
                SQL
            </a>

            <?php else: ?>
            <a href="?page=home" data-page="home" aria-label="Accueil">
                <div class="icons material-symbols-outlined">home</div>
                Accueil
            </a>

            <a href="?page=library" data-page="library" aria-label="Bibliothèque">
                <div class="icons material-symbols-outlined">newsstand</div>
                Bibliothèque
            </a>

            <a href="?page=search" data-page="search" aria-label="Recherche">
                <div class="icons material-symbols-outlined">search</div>
                Recherche
            </a>

            <a href="?page=import" data-page="import" aria-label="Importation">
                <div class="icons material-symbols-outlined">add</div>
                Importation
            </a>

            <?php
            /*
             * Messages cache l'activité, comme Compte cache la déconnexion :
             * même geste, même mécanique (scripts/navbar.js). Le fil
             * d'activité est une consultation occasionnelle — il n'avait pas
             * à prendre une case permanente dans une barre déjà pleine.
             */
            ?>
            <?php if ($partenaire !== null): ?>
            <div class="nav-repli">
                <a href="?page=activite" data-page="activite" aria-label="Activité" class="nav-replie" hidden>
                    <div class="icons material-symbols-outlined">history</div>
                    Activité
                </a>
                <a href="?page=messages" data-page="messages" aria-label="Messages" id="nav-messages"
                   class="nav-principale">
                    <div class="icons material-symbols-outlined">forum</div>
                    Messages
                    <span id="nav-messages-pastille" hidden></span>
                </a>
            </div>
            <?php endif; ?>

            <?php
            /*
             * Compte et déconnexion partagent une entrée.
             *
             * La barre en comptait sept avec Messages : sur mobile, les
             * libellés se chevauchaient. La déconnexion se révèle par un appui
             * long (ou un clic droit) sur le profil — un geste qu'on ne fait
             * pas par accident, ce qui remplace avantageusement la demande de
             * confirmation qui gardait cette entrée trop accessible.
             *
             * Elle reste par ailleurs dans Paramètres → Sécurité : un geste
             * caché ne doit jamais être le seul chemin vers une fonction.
             */
            ?>
            <div class="nav-repli nav-profil">
                <a href="actions/logout.php" id="nav-deconnexion" class="nav-replie" hidden>
                    <div class="icons material-symbols-outlined">logout</div>
                    Quitter
                </a>
                <a href="?page=account" data-page="account" aria-label="Compte" class="nav-principale">
                    <div class="icons material-symbols-outlined">person</div>
                    Compte
                </a>
            </div>
            <?php endif; ?>
        </nav>
    </footer>

    <!-- Le front adapte l'affichage ; le blocage réel reste côté serveur -->
    <script>
        window.UNISON_DEMO  = <?= $demo ? 'true' : 'false' ?>;
        window.UNISON_ADMIN = <?= $admin ? 'true' : 'false' ?>;
    </script>
    <?php if ($admin): ?>
    <?php
    /*
     * Chargé ici, et non plus par chaque page de gestion.
     *
     * Les pages le tiraient par une balise <script src> que le routeur
     * réinjectait à côté de leur script en ligne. Or un script externe se
     * charge de façon asynchrone, tandis qu'un script en ligne s'exécute dès
     * son insertion : à l'arrivée directe sur une page d'administration,
     * « window.AdminUnison » n'existait pas encore, le « if (!A) return; »
     * coupait court, et TOUT le JavaScript de la page restait mort — sans la
     * moindre erreur en console. Cela ne se voyait qu'au premier chargement,
     * puisqu'une navigation ultérieure retrouvait le script déjà chargé.
     */
    ?>
    <script src="<?= assetVersionne('scripts/admin.js') ?>"></script>
    <?php endif; ?>
    <script src="<?= assetVersionne('scripts/router.js') ?>"></script>

    <?php
    /*
     * Barre de navigation : pastille de messages et déconnexion repliée sous
     * le profil. Hors des pages, comme la barre elle-même.
     */
    ?>
    <?php if (!$admin): ?><script src="<?= assetVersionne('scripts/navbar.js') ?>"></script><?php endif; ?>
    <?php if (!$admin && $partenaire !== null): ?>
    <script src="<?= assetVersionne('scripts/presence.js') ?>"></script>
    <?php endif; ?>
    <?php if (!$admin): ?>
    <script src="<?= assetVersionne('scripts/bulk-import.js') ?>"></script>
    <?php endif; ?>
    <?php if ($demo): ?><script src="<?= assetVersionne('scripts/demo.js') ?>"></script><?php endif; ?>

</body>
</html>