<?php
include_once "../includes/auth.php";
exigerAdmin(false);
include_once "../includes/adminOutils.php";
include_once "../includes/adminGraphes.php";

$pdo = Config::getConnection();
$stats = statistiquesGlobales($pdo);
$stockage = analyserStockage($pdo);

$parJour  = ecoutesParJour($pdo, 30);
$parHeure = ecoutesParHeure($pdo);
$parMois  = ajoutsParMois($pdo, 12);

// Le nombre d'écoutes enregistrées conditionne l'intérêt de toute la section :
// on le mesure une fois pour décider quoi afficher.
$nbEcoutes = (int) $pdo->query("SELECT COUNT(*) FROM historical")->fetchColumn();
?>
<link rel="stylesheet" href="<?= assetVersionne('styles/admin.css') ?>">

<article class="containers admin-entete">
    <div class="head-bar">
        Administration
        <span class="admin-badge"><?= versionUnison(false) ?></span>
    </div>
    <div class="body-bar">
        <p class="admin-intro">
            Connecté en tant que <b><?= htmlspecialchars($_SESSION['user']['username'], ENT_QUOTES) ?></b>.
            Les opérations de cette section agissent sur l'ensemble du contenu,
            tous comptes confondus.
        </p>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Vue d'ensemble</div>
    <div class="body-bar">
        <div class="admin-chiffres">
            <div class="admin-chiffre"><span class="valeur"><?= $stats['titres'] ?></span>Titres</div>
            <div class="admin-chiffre"><span class="valeur"><?= $stats['artistes'] ?></span>Artistes</div>
            <div class="admin-chiffre"><span class="valeur"><?= $stats['playlists'] ?></span>Playlists</div>
            <div class="admin-chiffre"><span class="valeur"><?= $stats['genres'] ?></span>Genres</div>
            <div class="admin-chiffre"><span class="valeur"><?= $stats['tags'] ?></span>Étiquettes</div>
            <div class="admin-chiffre"><span class="valeur"><?= $stats['notes'] ?></span>Notes</div>
            <div class="admin-chiffre"><span class="valeur"><?= $stats['comptes'] ?></span>Comptes</div>
            <div class="admin-chiffre"><span class="valeur"><?= $stats['ecoutes'] ?></span>Écoutes</div>
            <div class="admin-chiffre"><span class="valeur"><?= formaterDuree($stats['duree']) ?></span>Catalogue</div>
            <div class="admin-chiffre"><span class="valeur"><?= formaterOctets($stockage['octets_total']) ?></span>Sur le disque</div>
        </div>
    </div>
</article>

<?php
// Les anomalies remontent en tête : c'est ce qui appelle une décision.
$nbOrphelins = count($stockage['orphelins']);
$nbManquants = count($stockage['manquants']);
if ($nbOrphelins || $nbManquants):
?>
<article class="containers admin-alerte">
    <div class="head-bar">À traiter</div>
    <div class="body-bar">
        <?php if ($nbOrphelins): ?>
            <div class="admin-ligne-alerte">
                <span class="material-symbols-outlined">folder_off</span>
                <span><b><?= $nbOrphelins ?></b> fichier(s) orphelin(s) —
                      <?= formaterOctets($stockage['octets_orphelins']) ?> occupés inutilement</span>
                <a href="?page=admin/stockage" data-page="admin/stockage" class="buttons">Voir</a>
            </div>
        <?php endif; ?>
        <?php if ($nbManquants): ?>
            <div class="admin-ligne-alerte">
                <span class="material-symbols-outlined">error</span>
                <span><b><?= $nbManquants ?></b> titre(s) sans fichier audio — visibles mais illisibles</span>
                <a href="?page=admin/stockage" data-page="admin/stockage" class="buttons">Voir</a>
            </div>
        <?php endif; ?>
    </div>
</article>
<?php endif; ?>

<article class="containers">
    <div class="head-bar">
        Écoutes
        <span class="more-bar"><?= $nbEcoutes ?> enregistrée(s)</span>
    </div>
    <div class="body-bar">
        <?php if ($nbEcoutes < 10): ?>
            <div class="admin-note">
                L'historique ne compte que <?= $nbEcoutes ?> écoute(s). Les graphiques
                deviennent parlants à partir de quelques dizaines : une écoute est
                enregistrée après 30 secondes de lecture effective.
            </div>
        <?php endif; ?>

        <div class="graphe-bloc">
            <div class="graphe-titre">30 derniers jours</div>
            <?= grapheBarres($parJour, 5, 'écoutes') ?>
        </div>

        <div class="graphe-bloc">
            <div class="graphe-titre">Répartition sur la journée</div>
            <?= grapheBarres($parHeure, 3, 'écoutes') ?>
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Palmarès</div>
    <div class="body-bar">
        <div class="graphe-colonnes">
            <div class="graphe-bloc">
                <div class="graphe-titre">Titres les plus écoutés</div>
                <?= grapheClassement(palmares($pdo, 'titres')) ?>
            </div>
            <div class="graphe-bloc">
                <div class="graphe-titre">Artistes les plus écoutés</div>
                <?= grapheClassement(palmares($pdo, 'artistes')) ?>
            </div>
            <div class="graphe-bloc">
                <div class="graphe-titre">Écoutes par compte</div>
                <?= grapheClassement(palmares($pdo, 'comptes')) ?>
            </div>
            <div class="graphe-bloc">
                <div class="graphe-titre">Genres les plus représentés</div>
                <?= grapheClassement(palmares($pdo, 'genres'), 'titres') ?>
            </div>
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Croissance de la bibliothèque</div>
    <div class="body-bar">
        <div class="graphe-bloc">
            <div class="graphe-titre">Titres ajoutés par mois</div>
            <?= grapheBarres($parMois, 1, 'titres') ?>
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Sections</div>
    <div class="body-bar">
        <div class="admin-sections">
            <a href="?page=admin/contenu" data-page="admin/contenu" class="admin-section">
                <span class="material-symbols-outlined">library_music</span>
                <span><b>Contenu</b><em>Supprimer et corriger titres, artistes, playlists</em></span>
            </a>
            <a href="?page=admin/stockage" data-page="admin/stockage" class="admin-section">
                <span class="material-symbols-outlined">hard_drive</span>
                <span><b>Stockage</b><em>Fichiers orphelins, titres sans fichier, images</em></span>
            </a>
            <a href="?page=admin/comptes" data-page="admin/comptes" class="admin-section">
                <span class="material-symbols-outlined">group</span>
                <span><b>Comptes</b><em>Rôles, mots de passe, désactivation</em></span>
            </a>
            <a href="?page=admin/maintenance" data-page="admin/maintenance" class="admin-section">
                <span class="material-symbols-outlined">build</span>
                <span><b>Maintenance</b><em>Recherche, conteneurs, mises à jour</em></span>
            </a>
        </div>
    </div>
</article>

<script src="<?= assetVersionne('../scripts/admin.js') ?>"></script>
