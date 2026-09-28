<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/rendu.php";
?>
<article id="etiquettes-choix" class="containers">
    <div class="head-bar">
        Étiquettes
        <span id="etiquettes-mode-zone" class="more-bar" hidden>
            <button type="button" id="etiquettes-mode" class="buttons">Croiser</button>
        </span>
    </div>
    <div class="body-bar">
        <p class="infos-note" id="etiquettes-aide">
            Cochez une ou plusieurs étiquettes. En mode « Croiser », un titre
            doit les porter <strong>toutes</strong> ; en mode « Cumuler », il
            lui suffit d'en porter une.
        </p>
        <div id="etiquettes-liste"><?= squelettes(2) ?></div>
    </div>
</article>

<article id="etiquettes-resultat" class="containers" hidden>
    <div class="head-bar">
        <span id="etiquettes-resume">Résultat</span>
    </div>
    <div class="body-bar">
        <div id="etiquettes-actions">
            <button type="button" class="buttons" id="et-ecouter">
                <span class="material-symbols-outlined">play_arrow</span> Écouter maintenant
            </button>
            <button type="button" class="buttons" id="et-file">
                <span class="material-symbols-outlined">playlist_play</span> Ajouter à la liste d'attente
            </button>
            <button type="button" class="buttons" id="et-suivant">
                <span class="material-symbols-outlined">low_priority</span> Écouter juste après
            </button>
        </div>
        <div id="etiquettes-titres"></div>
    </div>
</article>

<script>
(function () {
    const liste     = document.getElementById('etiquettes-liste');
    const resultat  = document.getElementById('etiquettes-resultat');
    const resume    = document.getElementById('etiquettes-resume');
    const corps     = document.getElementById('etiquettes-titres');
    const boutonMode= document.getElementById('etiquettes-mode');
    const zoneMode  = document.getElementById('etiquettes-mode-zone');
    if (!liste) return;

    /*
     * « et » par défaut : c'est le croisement qui fait l'intérêt de la page.
     * Cumuler plusieurs étiquettes revient à peu près à les parcourir une à
     * une, alors que les croiser désigne quelque chose qu'aucune ne dit seule.
     */
    let mode = 'et';
    const choisies = new Set();

    /** Le bouton dit ce que fait le mode courant, pas son nom technique. */
    function majMode() {
        boutonMode.textContent = mode === 'et' ? 'Croiser' : 'Cumuler';
        boutonMode.title = mode === 'et'
            ? "Les titres portent toutes les étiquettes cochées"
            : "Les titres portent au moins une des étiquettes cochées";
        zoneMode.hidden = choisies.size < 2;
    }

    async function chargerEtiquettes() {
        try {
            const data = await (await fetch('actions/etiquettes.php')).json();
            if (!data.success) throw new Error(data.message);

            liste.innerHTML = '';

            if (!data.etiquettes.length) {
                const vide = document.createElement('p');
                vide.className = 'infos-note';
                vide.textContent = "Aucune étiquette pour l'instant. On en crée depuis la fiche d'un titre.";
                liste.appendChild(vide);
                document.getElementById('etiquettes-aide').hidden = true;
                return;
            }

            data.etiquettes.forEach(e => {
                const l = document.createElement('label');
                l.className = 'tag-checkbox';

                const c = document.createElement('input');
                c.type = 'checkbox';
                c.value = e.id;

                // textContent : un nom d'étiquette est saisi par l'utilisateur.
                const nom = document.createElement('span');
                nom.textContent = e.name;

                const n = document.createElement('span');
                n.className = 'etiquette-compte';
                n.textContent = e.nb_titres;

                c.addEventListener('change', () => {
                    if (c.checked) choisies.add(e.id); else choisies.delete(e.id);
                    majMode();
                    chargerTitres();
                });

                l.append(c, nom, n);
                liste.appendChild(l);
            });
        } catch (err) {
            liste.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'infos-note';
            p.textContent = 'Chargement impossible.';
            liste.appendChild(p);
        }
    }

    async function chargerTitres() {
        if (!choisies.size) {
            resultat.hidden = true;
            return;
        }

        try {
            const url = 'actions/etiquettes.php?mode=' + mode
                      + '&tags=' + [...choisies].join(',');
            const data = await (await fetch(url)).json();
            if (!data.success) throw new Error(data.message);

            resultat.hidden = false;
            resume.textContent = data.total
                ? data.total + (data.total > 1 ? ' titres' : ' titre')
                : 'Aucun titre';

            // Les boutons d'action n'ont pas de sens sur un résultat vide.
            document.getElementById('etiquettes-actions').hidden = !data.total;

            window.remplirLignesTitres(corps, data.titres, {
                messageVide: "Aucun titre ne porte cette combinaison",
            });
        } catch (err) {
            resultat.hidden = true;
        }
    }

    boutonMode.addEventListener('click', () => {
        mode = mode === 'et' ? 'ou' : 'et';
        majMode();
        chargerTitres();
    });

    /** Envoie la sélection courante dans la file, selon la place demandée. */
    async function envoyer(position, remplacer) {
        if (!choisies.size) return;

        const corpsRequete = new URLSearchParams({
            tags: [...choisies].join(','),
            mode,
            position,
            remplacer: remplacer ? '1' : '0',
        });

        // Le serveur ne sait pas où en est la lecture : « juste après » se
        // calcule par rapport au titre que le lecteur joue.
        const enCours = window.waitPlaylist && window.waitPlaylist[window.currentIndex];
        if (position === 'suivant' && enCours) {
            corpsRequete.append('apres_track_id', String(enCours.id));
        }

        try {
            const res = await fetch('actions/file_ajouter_lot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: corpsRequete,
            });
            const data = await res.json();

            if (!data.success) {
                window.showToast(data.message || 'Opération impossible', 'error');
                return;
            }

            window.waitPlaylist = data.queue;
            if (remplacer) {
                window.currentIndex = 0;
                window.sourcePlaylistId = null;
                if (data.queue.length && typeof window.loadTrack === 'function') {
                    window.loadTrack(data.queue[0].id);
                }
            } else if (enCours) {
                const i = data.queue.findIndex(t => String(t.id) === String(enCours.id));
                if (i !== -1) window.currentIndex = i;
            }

            window.showToast(data.message);
        } catch (e) {
            window.showToast('Erreur réseau', 'error');
        }
    }

    document.getElementById('et-ecouter').addEventListener('click', () => envoyer('fin', true));
    document.getElementById('et-file').addEventListener('click', () => envoyer('fin', false));
    document.getElementById('et-suivant').addEventListener('click', () => envoyer('suivant', false));

    majMode();
    chargerEtiquettes();
})();
</script>
