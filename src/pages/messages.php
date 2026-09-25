<?php
/**
 * Mini-chat du foyer.
 *
 * Un seul fil, partagé par les deux comptes : avec deux personnes, une liste
 * de conversations n'aurait rien à lister.
 *
 * Un message porte un texte, un titre joint, ou les deux. Le titre joint se
 * lit sur place — c'est tout l'intérêt de l'envoyer ici plutôt que de dire
 * « écoute machin » de vive voix.
 */
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/config.php";

$partenaire = idPartenaire();
$demo = estDemo();

$nomPartenaire = '';
if ($partenaire !== null) {
    $req = Config::getConnection()->prepare("SELECT username FROM users WHERE id = :id");
    $req->execute([':id' => $partenaire]);
    $nomPartenaire = (string) $req->fetchColumn();
}
?>
<article class="containers" id="chat">
    <div class="head-bar">
        Messages
        <span class="more-bar"><?= htmlspecialchars($nomPartenaire ?: '—', ENT_QUOTES) ?></span>
    </div>

    <?php if ($partenaire === null): ?>
        <div class="body-bar">
            <p class="infos-note">Ce compte n'a personne avec qui discuter.</p>
        </div>
    <?php else: ?>

    <div class="body-bar" id="chat-fil">
        <div id="chat-etat">Chargement…</div>
    </div>

    <div id="chat-saisie">
        <!-- Titre joint en attente d'envoi, rempli par le script. -->
        <div id="chat-piece" hidden>
            <img id="chat-piece-img" alt="">
            <div id="chat-piece-infos">
                <div id="chat-piece-titre"></div>
                <div id="chat-piece-artiste"></div>
            </div>
            <button type="button" id="chat-piece-retirer"
                    class="material-symbols-outlined" title="Retirer le titre">close</button>
        </div>

        <div id="chat-barre">
            <button type="button" id="chat-joindre" class="material-symbols-outlined"
                    title="Joindre un titre" <?= $demo ? 'disabled' : '' ?>>music_note</button>
            <textarea id="chat-texte" rows="1" maxlength="2000"
                      placeholder="Écrire un message…" <?= $demo ? 'disabled' : '' ?>></textarea>
            <button type="button" id="chat-envoyer" class="material-symbols-outlined"
                    title="Envoyer (Ctrl + Entrée)" <?= $demo ? 'disabled' : '' ?>>send</button>
        </div>

        <!-- Recherche de titre à joindre, dépliée par le bouton ♪. -->
        <div id="chat-recherche" hidden>
            <input type="search" id="chat-recherche-champ" placeholder="Chercher un titre à joindre…">
            <div id="chat-recherche-resultats"></div>
        </div>
    </div>

    <?php endif; ?>
</article>

<script>
    (function () {
        const fil = document.getElementById('chat-fil');
        if (!fil) return;

        const etat      = document.getElementById('chat-etat');
        const texte     = document.getElementById('chat-texte');
        const envoyer   = document.getElementById('chat-envoyer');
        const joindre   = document.getElementById('chat-joindre');
        const piece     = document.getElementById('chat-piece');
        const recherche = document.getElementById('chat-recherche');
        const champ     = document.getElementById('chat-recherche-champ');
        const resultats = document.getElementById('chat-recherche-resultats');

        const MOI = <?= (int) $_SESSION['user']['id'] ?>;
        const RAFRAICHIR = 5000;

        let dernierId = 0;
        let jourCourant = null;
        let pieceJointe = null;   // { id, titre, artiste, img }
        let minuteur = null;

        const AUJOURDHUI = new Date().toDateString();
        const HIER = new Date(Date.now() - 86400000).toDateString();

        function nomDuJour(date) {
            const j = date.toDateString();
            if (j === AUJOURDHUI) return "Aujourd'hui";
            if (j === HIER) return 'Hier';
            const o = { weekday: 'long', day: 'numeric', month: 'long' };
            if (date.getFullYear() !== new Date().getFullYear()) o.year = 'numeric';
            return date.toLocaleDateString('fr-FR', o);
        }

        const heure = d => d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

        /*
         * Tout est construit par le DOM, jamais par innerHTML : le contenu
         * vient d'un autre compte, et un titre de musique peut contenir
         * n'importe quoi. Même règle que la console et le journal.
         */
        function bulle(m) {
            const bloc = document.createElement('div');
            bloc.className = 'chat-msg ' + (Number(m.expediteur_id) === MOI ? 'chat-msg--moi' : 'chat-msg--lui');

            const corps = document.createElement('div');
            corps.className = 'chat-bulle';

            if (m.contenu) {
                const t = document.createElement('div');
                t.className = 'chat-texte';
                t.textContent = m.contenu;
                corps.appendChild(t);
            }

            if (m.track_id) {
                const pj = document.createElement('button');
                pj.type = 'button';
                pj.className = 'chat-piece-jointe';
                pj.dataset.trackId = m.track_id;
                pj.title = 'Écouter ce titre';

                const img = document.createElement('img');
                img.alt = '';
                img.setAttribute('src', m.img || '');

                const infos = document.createElement('div');
                const ti = document.createElement('div');
                ti.className = 'chat-pj-titre';
                // Un titre supprimé depuis l'envoi : le message survit, la
                // pièce jointe le dit plutôt que d'afficher un vide.
                ti.textContent = m.titre || 'Titre supprimé';
                const ar = document.createElement('div');
                ar.className = 'chat-pj-artiste';
                ar.textContent = m.artistes || '';
                infos.append(ti, ar);

                const icone = document.createElement('span');
                icone.className = 'material-symbols-outlined';
                icone.textContent = 'play_arrow';

                pj.append(img, infos, icone);
                if (!m.titre) pj.disabled = true;
                corps.appendChild(pj);
            }

            const h = document.createElement('div');
            h.className = 'chat-heure';
            h.textContent = heure(new Date(Number(m.cree_ts) * 1000));

            bloc.append(corps, h);
            return bloc;
        }

        function ajouter(messages) {
            const frag = document.createDocumentFragment();

            messages.forEach(m => {
                const date = new Date(Number(m.cree_ts) * 1000);
                const jour = date.toDateString();

                if (jour !== jourCourant) {
                    jourCourant = jour;
                    const sep = document.createElement('div');
                    sep.className = 'historique-jour chat-jour';
                    sep.textContent = nomDuJour(date);
                    frag.appendChild(sep);
                }

                frag.appendChild(bulle(m));
                dernierId = Math.max(dernierId, Number(m.id));
            });

            fil.appendChild(frag);
            window.corrigerImagesVides && window.corrigerImagesVides(fil);
        }

        function auBas() {
            fil.scrollTop = fil.scrollHeight;
        }

        async function charger(premier) {
            try {
                const params = new URLSearchParams({ limite: '40', marquer: '1' });
                if (!premier) params.set('depuis', String(dernierId));

                const res = await fetch('actions/messages_lister.php?' + params);
                const data = await res.json();
                if (!data.success) throw new Error(data.message);

                if (premier) {
                    etat.remove();
                    if (data.messages.length === 0) {
                        const vide = document.createElement('p');
                        vide.className = 'infos-note';
                        vide.textContent = 'Aucun message. Envoyez le premier.';
                        fil.appendChild(vide);
                    }
                }

                if (data.messages.length) {
                    /*
                     * On ne recolle en bas que si l'on y était déjà : sinon on
                     * arracherait la lecture de quelqu'un en train de remonter
                     * le fil.
                     */
                    const enBas = fil.scrollHeight - fil.scrollTop - fil.clientHeight < 60;
                    ajouter(data.messages);
                    if (premier || enBas) auBas();
                }

                // La pastille de la barre suit : on vient de tout lire.
                window.majPastilleMessages && window.majPastilleMessages(0);
            } catch (e) {
                if (premier) etat.textContent = 'Messages indisponibles';
            }
        }

        /* ---------- Envoi ---------- */

        async function envoi() {
            const contenu = texte.value.trim();
            if (!contenu && !pieceJointe) return;

            envoyer.disabled = true;
            try {
                const corps = new URLSearchParams({ contenu });
                if (pieceJointe) corps.set('track_id', String(pieceJointe.id));

                const res = await fetch('actions/messages_envoyer.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: corps,
                });
                const data = await res.json();

                if (!data.success) {
                    window.showToast(data.message || 'Envoi impossible', 'error');
                    return;
                }

                texte.value = '';
                texte.style.height = '';
                retirerPiece();
                await charger(false);
                auBas();
            } catch (e) {
                window.showToast('Erreur réseau', 'error');
            } finally {
                envoyer.disabled = false;
            }
        }

        envoyer.addEventListener('click', envoi);

        // Ctrl + Entrée envoie ; Entrée seule reste un retour à la ligne, la
        // zone accepte plusieurs lignes.
        texte.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                if (!envoyer.disabled) envoi();
            }
        });

        // La zone grandit avec le texte, jusqu'à une limite : un pavé de
        // quinze lignes mangerait tout l'écran sur mobile.
        texte.addEventListener('input', () => {
            texte.style.height = 'auto';
            texte.style.height = Math.min(texte.scrollHeight, 120) + 'px';
        });

        /* ---------- Pièce jointe ---------- */

        function poserPiece(t) {
            pieceJointe = t;
            document.getElementById('chat-piece-img').setAttribute('src', t.img || '');
            document.getElementById('chat-piece-titre').textContent = t.titre;
            document.getElementById('chat-piece-artiste').textContent = t.artiste || '';
            piece.hidden = false;
            recherche.hidden = true;
            champ.value = '';
            resultats.replaceChildren();
            window.corrigerImagesVides && window.corrigerImagesVides(piece);
        }

        function retirerPiece() {
            pieceJointe = null;
            piece.hidden = true;
        }

        document.getElementById('chat-piece-retirer').addEventListener('click', retirerPiece);

        joindre.addEventListener('click', () => {
            recherche.hidden = !recherche.hidden;
            if (!recherche.hidden) champ.focus();
        });

        let rechercheEnCours = null;
        champ.addEventListener('input', () => {
            clearTimeout(rechercheEnCours);
            const q = champ.value.trim();
            if (q.length < 2) { resultats.replaceChildren(); return; }

            // Temporisé : une requête par frappe saturerait Meilisearch pour rien.
            rechercheEnCours = setTimeout(async () => {
                try {
                    const res = await fetch('actions/search.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ 'search-entry': q }),
                    });
                    const data = await res.json();

                    resultats.replaceChildren();
                    (data.musiques || []).slice(0, 6).forEach(t => {
                        const b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'chat-resultat';

                        const img = document.createElement('img');
                        img.alt = '';
                        img.setAttribute('src', t.img || '');

                        const infos = document.createElement('div');
                        const ti = document.createElement('div');
                        ti.className = 'chat-pj-titre';
                        ti.textContent = t.title ?? '';
                        const ar = document.createElement('div');
                        ar.className = 'chat-pj-artiste';
                        ar.textContent = t.artists_names ?? '';
                        infos.append(ti, ar);

                        b.append(img, infos);
                        b.addEventListener('click', () => poserPiece({
                            id: t.id, titre: t.title, artiste: t.artists_names, img: t.img,
                        }));
                        resultats.appendChild(b);
                    });

                    window.corrigerImagesVides && window.corrigerImagesVides(resultats);
                } catch (e) { /* la recherche muette n'empêche pas d'écrire */ }
            }, 250);
        });

        /* ---------- Lecture d'un titre joint ---------- */

        fil.addEventListener('click', (e) => {
            const pj = e.target.closest('.chat-piece-jointe');
            if (!pj || pj.disabled) return;
            window.loadTrack && window.loadTrack(Number(pj.dataset.trackId), true);
        });

        /* ---------- Rafraîchissement ---------- */

        /*
         * Plus vif que le battement de présence : on est dans la conversation,
         * quinze secondes d'attente s'y remarquent. S'arrête dès que l'onglet
         * passe en arrière-plan — la pastille de la barre prend le relais.
         */
        function demarrer() {
            arreter();
            minuteur = setInterval(() => charger(false), RAFRAICHIR);
        }
        function arreter() {
            clearInterval(minuteur);
            minuteur = null;
        }

        document.addEventListener('visibilitychange', () => {
            document.visibilityState === 'visible' ? demarrer() : arreter();
        });

        // Le routeur remplace #main-content sans prévenir : sans cela, le
        // minuteur d'une page quittée continuerait d'interroger le serveur.
        const observateur = new MutationObserver(() => {
            if (!document.body.contains(fil)) {
                arreter();
                observateur.disconnect();
            }
        });
        observateur.observe(document.getElementById('main-content') || document.body,
                           { childList: true, subtree: true });

        charger(true).then(demarrer);
    })();
</script>
