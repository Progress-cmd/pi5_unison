<?php
/**
 * Paramètres du compte.
 *
 * Anciennement « Infos » : la page contenait déjà des réglages (mode
 * d'affichage, mot de passe, sessions), elle ne portait simplement pas son nom.
 *
 * Deux natures de réglages cohabitent ici, et la distinction est assumée :
 *   - ceux qui suivent la personne (présence, thème, nom) vont en base ;
 *   - ceux qui dépendent de l'appareil (volume, reprise) restent dans le
 *     navigateur. Le volume du téléphone n'a rien à voir avec celui du PC.
 */
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/config.php";
include_once "../includes/viewMode.php";

$pdo = Config::getConnection();

$req = $pdo->prepare(
    "SELECT username, email, presence_visible, presence_partage_titre, theme
       FROM users WHERE id = :user_id"
);
$req->execute([':user_id' => (int) $_SESSION['user']['id']]);
$compte = $req->fetch(PDO::FETCH_ASSOC) ?: [];

$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$perso   = isPersonalView();
$visible = (int) ($compte['presence_visible'] ?? 1) === 1;
$partage = (int) ($compte['presence_partage_titre'] ?? 1) === 1;
$theme   = $compte['theme'] ?? 'systeme';
$demo    = estDemo();

/** Un interrupteur : libellé, explication, état. */
function reglage(string $id, string $titre, string $note, bool $actif, bool $desactive = false): void
{
    ?>
    <div class="reglage">
        <div class="reglage-texte">
            <span class="reglage-titre"><?= htmlspecialchars($titre, ENT_QUOTES) ?></span>
            <span class="reglage-note"><?= htmlspecialchars($note, ENT_QUOTES) ?></span>
        </div>
        <label class="interrupteur">
            <input type="checkbox" id="<?= htmlspecialchars($id, ENT_QUOTES) ?>"
                   <?= $actif ? 'checked' : '' ?> <?= $desactive ? 'disabled' : '' ?>>
            <span class="interrupteur-piste"></span>
        </label>
    </div>
    <?php
}
?>

<article class="containers" id="param-compte">
    <div class="head-bar">Compte</div>
    <div class="body-bar">
        <div class="infos-champs">
            <label>Nom affiché
                <input type="text" id="nom-affiche" maxlength="50"
                       value="<?= $e($compte['username'] ?? '') ?>"
                       <?= $demo ? 'disabled' : '' ?>>
            </label>
        </div>
        <p class="infos-note">
            Sert aussi à vous connecter, et donne l'initiale du cercle en haut
            de l'écran.
        </p>
        <div class="infos-actions">
            <button type="button" class="buttons infos-valider" id="nom-valider"
                    <?= $demo ? 'disabled' : '' ?>>Enregistrer</button>
        </div>
        <div class="content">
            <b>Email :</b>&nbsp;<?= $e($compte['email'] ?? '') ?: '—' ?>
        </div>
    </div>
</article>

<article class="containers" id="param-apparence">
    <div class="head-bar">Apparence</div>
    <div class="body-bar">
        <div class="reglage">
            <div class="reglage-texte">
                <span class="reglage-titre">Thème</span>
                <span class="reglage-note">« Système » suit le réglage clair ou sombre de votre appareil.</span>
            </div>
            <select id="choix-theme" class="param-select">
                <option value="systeme" <?= $theme === 'systeme' ? 'selected' : '' ?>>Système</option>
                <option value="clair"   <?= $theme === 'clair'   ? 'selected' : '' ?>>Clair</option>
                <option value="sombre"  <?= $theme === 'sombre'  ? 'selected' : '' ?>>Sombre</option>
            </select>
        </div>

        <div class="reglage">
            <div class="reglage-texte">
                <span class="reglage-titre">Contenu affiché</span>
                <span class="reglage-note">
                    <?= $perso
                        ? "Vous ne voyez que votre propre contenu : vos playlists, vos notes."
                        : "Vous voyez le contenu de tout le foyer : les playlists et les notes de chacun." ?>
                    Les deux cercles en haut de l'écran font la même bascule.
                </span>
            </div>
            <button type="button" class="buttons" id="basculer-vue"
                    data-mode="<?= $perso ? 'mixed' : 'personal' ?>">
                <?= $perso ? 'Voir tout' : 'Mon contenu' ?>
            </button>
        </div>
    </div>
</article>

<article class="containers" id="param-lecture">
    <div class="head-bar">Lecture</div>
    <div class="body-bar">
        <p class="infos-note">
            Ces trois réglages sont propres à cet appareil : ils ne suivent pas
            sur votre téléphone.
        </p>
        <?php
        // État réel posé par le script ci-dessous, qui lit le navigateur :
        // PHP ne connaît pas le localStorage.
        reglage('pref-volume',    'Mémoriser le volume',
                "Retrouve le niveau réglé la dernière fois.", true);
        reglage('pref-reprise',   'Reprendre où je m\'étais arrêté',
                "Recharge le dernier titre et sa position au démarrage.", true);
        reglage('pref-aleatoire', 'Lecture aléatoire par défaut',
                "Mélange la file d'attente à chaque nouvelle écoute.", false);
        ?>
    </div>
</article>

<article class="containers" id="param-presence">
    <div class="head-bar">Présence</div>
    <div class="body-bar">
        <?php
        reglage('pref-visible', 'Apparaître en ligne',
                "L'autre membre du foyer voit un point vert quand vous êtes connecté.",
                $visible);
        reglage('pref-partage', 'Partager ce que j\'écoute',
                "Sans cela, vous apparaissez seulement « en ligne », sans le titre.",
                $partage);
        ?>
        <p class="infos-note" id="param-presence-note"<?= $visible ? ' hidden' : '' ?>>
            Vous êtes invisible : l'autre vous voit hors ligne. Vous continuez
            de voir sa présence normalement.
        </p>
    </div>
</article>

<article class="containers" id="param-securite">
    <div class="head-bar">Sécurité</div>
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

        <p class="infos-note">
            Si vous avez laissé Unison ouvert sur un appareil que vous n'avez
            plus, fermez sa session d'ici. La session actuelle reste ouverte.
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
        const page = document.getElementById('param-compte');
        if (!page) return;

        const toast = (m, t, d) => window.showToast && window.showToast(m, t, d);

        /* ---------- Réglages enregistrés en base ---------- */

        async function enregistrer(champ, valeur) {
            try {
                const res = await fetch('actions/preferences.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ champ, valeur: String(valeur) }),
                });
                const data = await res.json();
                if (!data.success) toast(data.message, 'error');
                return data.success;
            } catch (e) {
                toast('Erreur réseau', 'error');
                return false;
            }
        }

        /*
         * Un interrupteur s'enregistre au basculement, sans bouton à valider.
         * En cas d'échec il revient à sa position : laisser la case cochée
         * ferait croire à un réglage appliqué qui ne l'est pas.
         */
        function brancherInterrupteur(id, champ, apres) {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('change', async () => {
                el.disabled = true;
                const ok = await enregistrer(champ, el.checked ? 1 : 0);
                if (!ok) el.checked = !el.checked;
                el.disabled = false;
                if (ok && apres) apres(el.checked);
            });
        }

        brancherInterrupteur('pref-visible', 'presence_visible', (actif) => {
            const note = document.getElementById('param-presence-note');
            if (note) note.hidden = actif;
        });
        brancherInterrupteur('pref-partage', 'presence_partage_titre');

        /* ---------- Thème ---------- */

        const choixTheme = document.getElementById('choix-theme');
        choixTheme.addEventListener('change', async () => {
            // Appliqué tout de suite : un thème qui attendrait la réponse du
            // serveur donnerait l'impression que le réglage n'a rien fait.
            window.appliquerTheme && window.appliquerTheme(choixTheme.value);
            await enregistrer('theme', choixTheme.value);
        });

        /* ---------- Réglages propres à l'appareil ---------- */

        /*
         * localStorage peut lever (navigation privée, stockage bloqué) : un
         * réglage de confort ne doit jamais empêcher la page de s'afficher.
         */
        const PREFS = window.unisonPrefs;

        [['pref-volume', 'memoriserVolume'],
         ['pref-reprise', 'reprise'],
         ['pref-aleatoire', 'aleatoire']].forEach(([id, cle]) => {
            const el = document.getElementById(id);
            if (!el || !PREFS) return;

            el.checked = PREFS.lire(cle);
            el.addEventListener('change', () => {
                PREFS.ecrire(cle, el.checked);
                toast('Réglage enregistré', 'success', 2000);
            });
        });

        /* ---------- Nom affiché ---------- */

        const nomChamp   = document.getElementById('nom-affiche');
        const nomValider = document.getElementById('nom-valider');
        nomValider.addEventListener('click', async () => {
            const nom = nomChamp.value.trim();
            if (nom.length < 2) {
                toast('Le nom doit faire au moins 2 caractères', 'error');
                return;
            }

            nomValider.disabled = true;
            try {
                const res = await fetch('actions/changer_nom.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ nom }),
                });
                const data = await res.json();
                toast(data.message, data.success ? 'success' : 'error');

                // L'en-tête vit hors de #main-content : la page ne le redessine
                // pas, il faut lui porter le nouveau nom.
                if (data.success) window.majNomAffiche && window.majNomAffiche(data.nom);
            } catch (e) {
                toast('Erreur réseau', 'error');
            } finally {
                nomValider.disabled = false;
            }
        });

        /* ---------- Mode d'affichage ---------- */

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
            navigateTo('account/parametres');
        });

        /* ---------- Mot de passe ---------- */

        const valider = document.getElementById('mdp-valider');
        valider.addEventListener('click', async () => {
            const actuel   = document.getElementById('mdp-actuel').value;
            const nouveau  = document.getElementById('mdp-nouveau').value;
            const confirme = document.getElementById('mdp-confirme').value;

            if (!actuel || !nouveau) {
                toast('Renseignez les deux champs', 'error');
                return;
            }
            // Vérifié ici pour éviter un aller-retour inutile ; le serveur
            // refait tous les contrôles de son côté.
            if (nouveau !== confirme) {
                toast('Les deux nouveaux mots de passe diffèrent', 'error');
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
                toast(data.message, data.success ? 'success' : 'error', data.success ? 5000 : 0);

                if (data.success) {
                    ['mdp-actuel', 'mdp-nouveau', 'mdp-confirme']
                        .forEach(id => { document.getElementById(id).value = ''; });
                }
            } catch (e) {
                toast('Erreur réseau', 'error');
            } finally {
                valider.disabled = false;
            }
        });

        /* ---------- Sessions ---------- */

        const fermer = document.getElementById('fermer-sessions');
        fermer.addEventListener('click', async () => {
            if (!confirm('Déconnecter tous les autres appareils ?')) return;

            fermer.disabled = true;
            try {
                const res = await fetch('actions/deconnecter_autres.php', { method: 'POST' });
                const data = await res.json();
                toast(data.message, data.success ? 'success' : 'error');
            } catch (e) {
                toast('Erreur réseau', 'error');
            } finally {
                fermer.disabled = false;
            }
        });
    })();
</script>
