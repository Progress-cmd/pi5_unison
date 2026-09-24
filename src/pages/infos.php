<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/config.php";
include_once "../includes/viewMode.php";

$pdo = Config::getConnection();

$req = $pdo->prepare("SELECT username, email FROM users WHERE id = :user_id");
$req->execute([':user_id' => (int) $_SESSION['user']['id']]);
$compte = $req->fetch(PDO::FETCH_ASSOC) ?: ['username' => '', 'email' => ''];

$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$perso = isPersonalView();
?>
<article id="account-infos" class="containers">
    <div class="head-bar">Informations</div>
    <div class="body-bar">
        <div class="content">
            <b>Nom d'utilisateur :</b>&nbsp;<?= $e($compte['username']) ?>
        </div>
        <div class="content">
            <b>Email :</b>&nbsp;<?= $e($compte['email'] ?: '—') ?>
        </div>
    </div>
</article>

<article class="containers" id="infos-affichage">
    <div class="head-bar">Mode d'affichage</div>
    <div class="body-bar">
        <p class="infos-note">
            <?= $perso
                ? "Vous ne voyez que votre propre contenu : vos playlists, vos notes."
                : "Vous voyez le contenu de tout le foyer : les playlists et les notes de chacun." ?>
            Les deux cercles en haut de l'écran font la même bascule.
        </p>
        <div class="infos-actions">
            <button type="button" class="buttons" id="basculer-vue"
                    data-mode="<?= $perso ? 'mixed' : 'personal' ?>">
                <?= $perso ? 'Voir le contenu commun' : 'Ne voir que mon contenu' ?>
            </button>
        </div>
    </div>
</article>

<article class="containers" id="infos-mdp">
    <div class="head-bar">Mot de passe</div>
    <div class="body-bar">
        <p class="infos-note">
            Changer votre mot de passe déconnectera vos autres appareils.
        </p>
        <div class="infos-champs">
            <label>Mot de passe actuel
                <input type="password" id="mdp-actuel" autocomplete="current-password">
            </label>
            <label>Nouveau mot de passe (10 caractères minimum)
                <input type="password" id="mdp-nouveau" autocomplete="new-password">
            </label>
            <label>Confirmer le nouveau mot de passe
                <input type="password" id="mdp-confirme" autocomplete="new-password">
            </label>
        </div>
        <div class="infos-actions">
            <button type="button" class="buttons infos-valider" id="mdp-valider">Modifier</button>
        </div>
    </div>
</article>

<article class="containers" id="infos-sessions">
    <div class="head-bar">Appareils connectés</div>
    <div class="body-bar">
        <p class="infos-note">
            Si vous avez laissé Unison ouvert sur un appareil que vous n'avez plus,
            fermez sa session d'ici. La session actuelle reste ouverte.
        </p>
        <div class="infos-actions">
            <button type="button" class="buttons" id="fermer-sessions">
                Déconnecter les autres appareils
            </button>
        </div>
    </div>
</article>

<script>
    (function () {
        const bloc = document.getElementById('infos-mdp');
        if (!bloc) return;

        // --- Mode d'affichage
        const bascule = document.getElementById('basculer-vue');
        bascule.addEventListener('click', async () => {
            const mode = bascule.dataset.mode;
            bascule.disabled = true;
            try {
                await fetch('actions/set_view_mode.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'mode=' + encodeURIComponent(mode),
                });
            } catch (e) { /* le rechargement dira l'état réel */ }

            /*
             * Les deux cercles de l'en-tête vivent hors de #main-content : la
             * navigation qui suit ne les redessine pas. Sans cet appel, ils
             * restaient sur l'ancien mode alors que la bascule venait de
             * changer — deux indicateurs contradictoires à l'écran.
             */
            window.majBasculeAffichage && window.majBasculeAffichage(mode);

            // Rechargée plutôt que basculée sur place : les listes et cette
            // page doivent toutes refléter le même mode.
            navigateTo('account/infos');
        });

        // --- Mot de passe
        const valider = document.getElementById('mdp-valider');
        valider.addEventListener('click', async () => {
            const actuel   = document.getElementById('mdp-actuel').value;
            const nouveau  = document.getElementById('mdp-nouveau').value;
            const confirme = document.getElementById('mdp-confirme').value;

            if (!actuel || !nouveau) {
                window.showToast('Renseignez les deux champs', 'error');
                return;
            }
            // Vérifié ici pour éviter un aller-retour inutile ; le serveur
            // refait tous les contrôles de son côté.
            if (nouveau !== confirme) {
                window.showToast('Les deux nouveaux mots de passe diffèrent', 'error');
                return;
            }

            valider.disabled = true;
            try {
                const res = await fetch('actions/changer_mdp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ actuel, nouveau }),
                });
                const data = await res.json();
                window.showToast(data.message, data.success ? 'success' : 'error', data.success ? 5000 : 0);

                if (data.success) {
                    ['mdp-actuel', 'mdp-nouveau', 'mdp-confirme']
                        .forEach(id => { document.getElementById(id).value = ''; });
                }
            } catch (e) {
                window.showToast('Erreur réseau', 'error');
            } finally {
                valider.disabled = false;
            }
        });

        // --- Sessions
        const fermer = document.getElementById('fermer-sessions');
        fermer.addEventListener('click', async () => {
            if (!confirm('Déconnecter tous les autres appareils ?')) return;

            fermer.disabled = true;
            try {
                const res = await fetch('actions/deconnecter_autres.php', { method: 'POST' });
                const data = await res.json();
                window.showToast(data.message, data.success ? 'success' : 'error');
            } catch (e) {
                window.showToast('Erreur réseau', 'error');
            } finally {
                fermer.disabled = false;
            }
        });
    })();
</script>
