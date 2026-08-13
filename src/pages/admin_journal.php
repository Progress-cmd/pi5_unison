<?php
include_once "../includes/auth.php";
exigerAdmin(false);
include_once "../includes/adminOutils.php";
include_once "../includes/adminGraphes.php";
include_once "../includes/journalRapport.php";

// Le journal vit dans la base principale, quelle que soit la session.
$pdo = Config::getConnectionPrincipale();

$installe = journalTableExiste($pdo);
$stats    = journalStatistiques($pdo);
$taille   = journalTailleTable($pdo);
$parJour  = journalParJour($pdo, 14);

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= assetVersionne('styles/admin.css') ?>">

<article class="containers" data-csrf="<?= $e(jetonCsrf()) ?>">
    <div class="head-bar">
        <a href="?page=admin" data-page="admin" class="redirect">← Administration</a>
    </div>
</article>

<?php if (!$installe): ?>
<article class="containers">
    <div class="head-bar">Journal</div>
    <div class="body-bar">
        <div class="admin-note attention">
            <b>La table du journal n'existe pas.</b><br>
            Elle est créée automatiquement sur une base vierge, mais doit être
            ajoutée à la main sur une installation déjà en service.
        </div>
        <pre class="admin-note">cd docker &amp;&amp; set -a &amp;&amp; . ./.env &amp;&amp; set +a &amp;&amp; cd ..
docker compose -f docker/docker-compose-prod.yml exec -T db \
    mariadb -u root -p"$DB_ROOTPASS" "$DB_NAME" &lt; mysql_init/migrations/002_journal.sql</pre>
    </div>
</article>

<?php else: ?>

<article class="containers">
    <div class="head-bar">
        Journal
        <span class="more-bar"><?= number_format($stats['total'], 0, ',', ' ') ?> événement(s)</span>
    </div>
    <div class="body-bar">
        <div class="admin-chiffres">
            <div class="admin-chiffre"><span class="valeur"><?= $stats['jour'] ?></span>Dernières 24 h</div>
            <div class="admin-chiffre"><span class="valeur"><?= $stats['semaine'] ?></span>7 derniers jours</div>
            <div class="admin-chiffre<?= $stats['incidents_24h'] ? ' alerte' : '' ?>">
                <span class="valeur"><?= $stats['incidents_24h'] ?></span>Incidents 24 h
            </div>
            <div class="admin-chiffre"><span class="valeur"><?= formaterOctets($taille) ?></span>Sur le disque</div>
        </div>

        <?php if ($stats['total'] === 0): ?>
            <div class="admin-note">
                Le journal est vide. Il se remplit tout seul : connexions,
                importations, opérations d'administration et incidents y sont
                écrits au fil de l'eau.
            </div>
        <?php else: ?>
            <div class="graphe-bloc">
                <div class="graphe-titre">Activité des 14 derniers jours</div>
                <?= grapheBarres($parJour, 5, 'événements') ?>
            </div>
        <?php endif; ?>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Filtres</div>
    <div class="body-bar">
        <div class="journal-filtres">
            <label>
                Niveau minimum
                <select id="filtre-niveau">
                    <option value="">Tous</option>
                    <?php foreach (JOURNAL_NIVEAUX as $niveau): ?>
                        <option value="<?= $e($niveau) ?>"><?= $e(ucfirst($niveau)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Canal
                <select id="filtre-canal">
                    <option value="">Tous</option>
                    <?php foreach (JOURNAL_CANAUX as $cle => $libelle): ?>
                        <option value="<?= $e($cle) ?>">
                            <?= $e($libelle) ?><?php
                                $n = $stats['par_canal'][$cle] ?? 0;
                                echo $n ? " ($n)" : '';
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Période
                <select id="filtre-heures">
                    <option value="">Depuis toujours</option>
                    <option value="1">Dernière heure</option>
                    <option value="24">24 dernières heures</option>
                    <option value="168">7 derniers jours</option>
                    <option value="720">30 derniers jours</option>
                </select>
            </label>

            <label class="journal-filtre-large">
                Recherche
                <input type="search" id="filtre-recherche" class="admin-filtre"
                       placeholder="message, action, compte…">
            </label>
        </div>

        <div class="admin-actions">
            <button class="admin-btn" id="journal-rafraichir">Rafraîchir</button>
            <button class="admin-btn" id="journal-reinitialiser">Réinitialiser les filtres</button>
            <label class="journal-auto">
                <input type="checkbox" id="journal-auto"> Rafraîchissement automatique
            </label>
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">
        Événements
        <span class="more-bar" id="journal-compteur">…</span>
    </div>
    <div class="body-bar">
        <!--
            Le tableau est rempli par scripts/journal.js : la page sert
            l'ossature et les filtres, le contenu arrive de actions/admin/journal.php.
            Cela évite de recharger la page entière à chaque changement de
            filtre, et permet le rafraîchissement automatique.
        -->
        <div class="admin-table-enveloppe">
            <table class="admin-table journal-table" id="journal-table">
                <thead>
                    <tr>
                        <th>Quand</th><th>Niveau</th><th>Canal</th>
                        <th>Événement</th><th>Compte</th>
                    </tr>
                </thead>
                <tbody id="journal-corps">
                    <tr><td colspan="5" class="admin-vide">Chargement…</td></tr>
                </tbody>
            </table>
        </div>

        <div class="journal-pagination">
            <button class="admin-btn" id="journal-precedent" disabled>← Plus récents</button>
            <span id="journal-page">page 1</span>
            <button class="admin-btn" id="journal-suivant" disabled>Plus anciens →</button>
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Entretien</div>
    <div class="body-bar">
        <div class="admin-note">
            Les événements de plus de <b><?= JOURNAL_RETENTION_JOURS ?> jours</b> sont
            supprimés automatiquement, au plus une fois par jour, quand cette
            section est consultée. Ce bouton permet de le faire tout de suite,
            ou de raccourcir la rétention ponctuellement.
            <?php if ($stats['plus_ancien']): ?>
                <br>Plus ancien événement conservé : <b><?= $e(journalQuand($stats['plus_ancien'])) ?></b>.
            <?php endif; ?>
        </div>
        <div class="admin-actions">
            <button class="admin-btn danger" id="journal-purger">Purger le journal</button>
        </div>
    </div>
</article>

<?php endif; ?>

<script src="<?= assetVersionne('../scripts/admin.js') ?>"></script>
<script src="<?= assetVersionne('../scripts/journal.js') ?>"></script>
