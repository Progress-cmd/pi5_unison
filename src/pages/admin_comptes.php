<?php
include_once "../includes/auth.php";
exigerAdmin(false);
include_once "../includes/adminOutils.php";

$pdo = Config::getConnection();
$comptes = listerComptes($pdo);
$moi = (int) $_SESSION['user']['id'];

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= assetVersionne('styles/admin.css') ?>">

<article class="containers" data-csrf="<?= $e(jetonCsrf()) ?>">
    <div class="head-bar">
        <a href="?page=admin" data-page="admin" class="redirect">← Administration</a>
    </div>
    <div class="body-bar">
        <div class="admin-note">
            <b>Désactiver plutôt que supprimer.</b> La désactivation bloque la
            connexion mais conserve les titres importés, les playlists et
            l'historique, et se défait. La suppression exige de réattribuer tout
            ce contenu à un autre compte — la base refuse de laisser des titres
            sans propriétaire.
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Comptes<span class="result-section-nb"><?= count($comptes) ?></span></div>
    <div class="body-bar">
        <div class="admin-table-enveloppe">
            <table class="admin-table" id="table-comptes">
                <thead>
                    <tr>
                        <th>Compte</th><th>Rôle</th><th>Titres</th><th>Playlists</th>
                        <th>Écoutes</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($comptes as $c):
                    $estMoi = ((int) $c['id'] === $moi); ?>
                    <tr data-id="<?= (int) $c['id'] ?>" data-role="<?= $e($c['role']) ?>">
                        <td class="principal" data-titre><?= $e($c['username']) ?><?= $estMoi ? ' <em>(vous)</em>' : '' ?></td>
                        <td><span class="admin-role <?= $e($c['role']) ?>"><?= $e($c['role']) ?></span></td>
                        <td><?= (int) $c['nb_titres'] ?></td>
                        <td><?= (int) $c['nb_playlists'] ?></td>
                        <td><?= (int) $c['nb_ecoutes'] ?></td>
                        <td class="admin-actions">
                            <?php if ($estMoi): ?>
                                <span class="admin-vide" style="padding:0">Votre compte</span>
                            <?php else: ?>
                                <button class="admin-btn" data-action="mdp">Mot de passe</button>
                                <?php if ($c['role'] !== 'admin'): ?>
                                    <button class="admin-btn" data-action="role" data-role="admin">Promouvoir</button>
                                <?php else: ?>
                                    <button class="admin-btn" data-action="role" data-role="user">Rétrograder</button>
                                <?php endif; ?>
                                <?php if ($c['role'] !== 'desactive'): ?>
                                    <button class="admin-btn danger" data-action="role" data-role="desactive">Désactiver</button>
                                <?php else: ?>
                                    <button class="admin-btn" data-action="role" data-role="user">Réactiver</button>
                                <?php endif; ?>
                                <button class="admin-btn danger" data-action="supprimer">Supprimer</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</article>

<article class="containers">
    <div class="head-bar">Ajouter un compte</div>
    <div class="body-bar">
        <div class="admin-note">
            La création se fait en ligne de commande, pour que le mot de passe ne
            transite jamais par le navigateur ni par un fichier versionné :
            <code>docker compose exec app php /var/www/html/src/includes/creerAdmin.php</code>
            Ce script crée un administrateur, ou promeut un compte existant.
        </div>
    </div>
</article>

<script src="<?= assetVersionne('../scripts/admin.js') ?>"></script>
<script>
(function () {
    const A = window.AdminUnison;
    if (!A) return;

    // Liste des repreneurs possibles, construite depuis le tableau affiché.
    function autresComptes(exclu) {
        return Array.from(document.querySelectorAll('#table-comptes tbody tr'))
            .filter(tr => tr.dataset.id !== exclu)
            .map(tr => ({
                id:  tr.dataset.id,
                nom: tr.querySelector('[data-titre]').textContent.replace('(vous)', '').trim(),
            }));
    }

    document.getElementById('main-content').addEventListener('click', async (e) => {
        const btn = e.target.closest('.admin-btn[data-action]');
        if (!btn) return;

        const tr  = btn.closest('tr');
        const id  = tr.dataset.id;
        const nom = tr.querySelector('[data-titre]').textContent.replace('(vous)', '').trim();

        if (btn.dataset.action === 'mdp') {
            if (!confirm(`Générer un nouveau mot de passe pour « ${nom} » ?\n\nL'ancien cessera immédiatement de fonctionner.`)) return;

            const r = await A.appeler('reinit_mdp.php', { user_id: id });
            if (r && r.success) {
                // Affiché une seule fois : rien n'est conservé en clair.
                alert(`Mot de passe temporaire de « ${nom} » :\n\n${r.mot_de_passe}\n\n` +
                      `Notez-le maintenant, il ne sera plus affiché.`);
            }
            return;
        }

        if (btn.dataset.action === 'role') {
            const role = btn.dataset.role;
            const libelle = { admin: 'promouvoir administrateur', user: 'passer en compte normal',
                              desactive: 'désactiver' }[role];
            if (!confirm(`Confirmer : ${libelle} « ${nom} » ?`)) return;

            const r = await A.appeler('changer_role.php', { user_id: id, role });
            if (r && r.success) navigateTo('admin/comptes');
            return;
        }

        if (btn.dataset.action === 'supprimer') {
            const autres = autresComptes(id);
            if (!autres.length) {
                alert("Aucun compte repreneur disponible : la suppression est impossible.");
                return;
            }

            const liste = autres.map(c => `${c.id} — ${c.nom}`).join('\n');
            const choix = prompt(
                `Suppression de « ${nom} ».\n\n` +
                `Ses titres et playlists doivent être réattribués.\n` +
                `Saisissez l'identifiant du compte repreneur :\n\n${liste}`
            );
            if (choix === null) return;

            const repreneur = autres.find(c => c.id === choix.trim());
            if (!repreneur) {
                alert("Identifiant de repreneur invalide.");
                return;
            }

            if (!A.confirmerParNom(nom, `ce compte (contenu transféré à « ${repreneur.nom} »)`)) return;

            const r = await A.appeler('supprimer_compte.php', {
                user_id: id, repreneur_id: repreneur.id, confirmation: nom,
            });
            if (r && r.success) navigateTo('admin/comptes');
        }
    });
})();
</script>
