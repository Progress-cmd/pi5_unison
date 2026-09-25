<?php
/**
 * Annonces : nouveautés et messages affichés une fois à chaque compte.
 *
 * Le « une seule fois » n'est pas géré ici mais par la table annonces_vues,
 * dont la clé composée (annonce_id, user_id) rend un doublon impossible.
 */
include_once "../includes/auth.php";
exigerAdmin(false);
include_once "../includes/config.php";

$pdo = Config::getConnection();

/*
 * Chaque annonce avec le nombre de comptes qui l'ont vue, et le total des
 * comptes concernés : c'est la seule information utile après publication —
 * savoir si elle est passée.
 */
$destinataires = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role != 'admin'"
)->fetchColumn();

$annonces = $pdo->query("
    SELECT a.id, a.titre, a.contenu, a.type, a.actif,
           UNIX_TIMESTAMP(a.cree_a) AS cree_ts,
           COUNT(v.user_id) AS vues
      FROM annonces a
      LEFT JOIN annonces_vues v ON v.annonce_id = a.id
     GROUP BY a.id, a.titre, a.contenu, a.type, a.actif, a.cree_a
     ORDER BY a.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= assetVersionne('styles/admin.css') ?>">

<article class="containers" data-csrf="<?= $e(jetonCsrf()) ?>" id="annonces-page">
    <div class="head-bar">
        <a href="?page=admin" data-page="admin" class="redirect">← Administration</a>
    </div>
    <div class="body-bar">
        <div class="admin-note">
            <b>Une annonce s'affiche une fois par compte.</b> Une fois lue, elle
            ne revient pas, même si on la désactive puis la réactive. Pour la
            faire revoir à tout le monde, supprimez-la et recréez-la : la
            suppression efface aussi qui l'avait vue.
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Nouvelle annonce</div>
    <div class="body-bar">
        <div class="infos-champs">
            <label>Titre
                <input type="text" id="annonce-titre" maxlength="120"
                       placeholder="Ex. : Les messages arrivent">
            </label>
            <label>Message
                <textarea id="annonce-contenu" rows="4"
                          placeholder="Ce que vous voulez leur dire. Les retours à la ligne sont conservés."></textarea>
            </label>
            <label>Type
                <select id="annonce-type" class="param-select">
                    <option value="fonctionnalite">Nouvelle fonctionnalité</option>
                    <option value="message">Message</option>
                </select>
            </label>
        </div>
        <div class="infos-actions">
            <button type="button" class="buttons infos-valider" id="annonce-publier">Publier</button>
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">
        Annonces<span class="result-section-nb"><?= count($annonces) ?></span>
    </div>
    <div class="body-bar" id="annonces-liste">
        <?php if (!$annonces): ?>
            <p class="infos-note">Aucune annonce pour le moment.</p>
        <?php endif; ?>

        <?php foreach ($annonces as $a): ?>
        <div class="annonce-ligne" data-id="<?= (int) $a['id'] ?>">
            <div class="annonce-ligne-texte">
                <div class="annonce-ligne-titre">
                    <span class="material-symbols-outlined"><?=
                        $a['type'] === 'fonctionnalite' ? 'auto_awesome' : 'campaign' ?></span>
                    <b><?= $e($a['titre']) ?></b>
                    <?php if (!$a['actif']): ?>
                        <span class="annonce-retiree">retirée</span>
                    <?php endif; ?>
                </div>
                <div class="annonce-ligne-note">
                    Vue par <?= (int) $a['vues'] ?> / <?= $destinataires ?> ·
                    <?= date('d/m/Y H:i', (int) $a['cree_ts']) ?>
                </div>
            </div>
            <div class="annonce-ligne-actions">
                <button type="button" class="buttons" data-op="basculer">
                    <?= $a['actif'] ? 'Retirer' : 'Réactiver' ?>
                </button>
                <button type="button" class="buttons" data-op="supprimer">Supprimer</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</article>

<script>
    (function () {
        const page = document.getElementById('annonces-page');
        if (!page) return;

        const toast = (m, t) => window.showToast && window.showToast(m, t);

        async function appeler(corps) {
            const res = await fetch('actions/admin/annonce.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(corps),
            });
            return res.json();
        }

        const publier = document.getElementById('annonce-publier');
        publier.addEventListener('click', async () => {
            const titre   = document.getElementById('annonce-titre').value.trim();
            const contenu = document.getElementById('annonce-contenu').value.trim();
            const type    = document.getElementById('annonce-type').value;

            if (!titre || !contenu) {
                toast('Titre et message sont requis', 'error');
                return;
            }

            publier.disabled = true;
            try {
                const data = await appeler({ operation: 'creer', titre, contenu, type });
                toast(data.message, data.success ? 'success' : 'error');
                // Rechargée : la liste doit montrer la nouvelle annonce et ses
                // compteurs, que le serveur seul connaît.
                if (data.success) navigateTo('admin/annonces');
            } catch (e) {
                toast('Erreur réseau', 'error');
            } finally {
                publier.disabled = false;
            }
        });

        document.getElementById('annonces-liste').addEventListener('click', async (e) => {
            const bouton = e.target.closest('[data-op]');
            if (!bouton) return;

            const ligne = bouton.closest('.annonce-ligne');
            const op = bouton.dataset.op;

            if (op === 'supprimer'
                && !confirm("Supprimer cette annonce ? Les comptes qui ne l'ont pas vue ne la verront jamais.")) {
                return;
            }

            bouton.disabled = true;
            try {
                const data = await appeler({ operation: op, id: ligne.dataset.id });
                toast(data.message, data.success ? 'success' : 'error');
                if (data.success) navigateTo('admin/annonces');
            } catch (e) {
                toast('Erreur réseau', 'error');
            } finally {
                bouton.disabled = false;
            }
        });
    })();
</script>
