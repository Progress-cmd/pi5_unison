<?php
include_once "../includes/auth.php";
exigerConnexion(false);
include_once "../includes/rendu.php";
?>
<article id="etiquettes-choix" class="containers">
    <div class="head-bar">
        Genres et étiquettes
        <span id="etiquettes-mode-zone" class="more-bar" hidden>
            <button type="button" id="etiquettes-mode" class="buttons">Croiser</button>
        </span>
    </div>
    <div class="body-bar">
        <p class="infos-note" id="etiquettes-aide">
            Cochez des genres, des étiquettes, ou les deux. En mode
            « Croiser », un titre doit <strong>tout</strong> porter — « Rock »
            et « calme » ensemble ; en mode « Cumuler », il lui suffit d'un
            seul critère.
        </p>

        <h3 class="etiquettes-groupe">Genres</h3>
        <p class="infos-note">Ce qu'est la musique — renseigné par l'import, partagé avec l'artiste.</p>
        <div id="genres-liste"><?= squelettes(1) ?></div>

        <h3 class="etiquettes-groupe">Étiquettes</h3>
        <p class="infos-note">Vos mots à vous — posés à la main, sur un titre ou sur une playlist.</p>
        <div id="etiquettes-liste"><?= squelettes(1) ?></div>
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

    /*
     * Deux ensembles distincts : un genre et une étiquette peuvent porter le
     * même identifiant, les mélanger dans un seul Set croiserait des critères
     * qui n'ont rien à voir.
     */
    const choisies = { tags: new Set(), genres: new Set() };
    const nbChoisies = () => choisies.tags.size + choisies.genres.size;

    /** Le bouton dit ce que fait le mode courant, pas son nom technique. */
    function majMode() {
        boutonMode.textContent = mode === 'et' ? 'Croiser' : 'Cumuler';
        boutonMode.title = mode === 'et'
            ? "Les titres portent toutes les étiquettes cochées"
            : "Les titres portent au moins une des étiquettes cochées";
        zoneMode.hidden = nbChoisies() < 2;
    }

    async function chargerEtiquettes() {
        try {
            const data = await (await fetch('actions/etiquettes.php')).json();
            if (!data.success) throw new Error(data.message);

            /** Dessine une famille de pastilles dans son conteneur. */
            const peupler = (conteneur, entrees, ensemble, motVide) => {
                conteneur.innerHTML = '';

                if (!entrees.length) {
                    const vide = document.createElement('p');
                    vide.className = 'infos-note';
                    vide.textContent = motVide;
                    conteneur.appendChild(vide);
                    return;
                }

                entrees.forEach(e => {
                    const l = document.createElement('label');
                    l.className = 'tag-checkbox';

                    const c = document.createElement('input');
                    c.type = 'checkbox';
                    c.value = e.id;

                    // textContent : ces noms sont saisis par l'utilisateur.
                    const nom = document.createElement('span');
                    nom.textContent = e.name;

                    const n = document.createElement('span');
                    n.className = 'etiquette-compte';
                    n.textContent = e.nb_titres;

                    c.addEventListener('change', () => {
                        if (c.checked) ensemble.add(e.id); else ensemble.delete(e.id);
                        majMode();
                        chargerTitres();
                    });

                    l.append(c, nom, n);
                    conteneur.appendChild(l);
                });
            };

            peupler(document.getElementById('genres-liste'), data.genres, choisies.genres,
                    "Aucun genre pour l'instant. On en crée depuis la fiche d'un titre.");
            peupler(liste, data.etiquettes, choisies.tags,
                    "Aucune étiquette pour l'instant. On en crée depuis la fiche d'un titre.");

            if (!data.genres.length && !data.etiquettes.length) {
                document.getElementById('etiquettes-aide').hidden = true;
            }
        } catch (err) {
            liste.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'infos-note';
            p.textContent = 'Chargement impossible.';
            liste.appendChild(p);
        }
    }

    async function chargerTitres() {
        if (!nbChoisies()) {
            resultat.hidden = true;
            return;
        }

        try {
            const url = 'actions/etiquettes.php?mode=' + mode
                      + '&tags=' + [...choisies.tags].join(',')
                      + '&genres=' + [...choisies.genres].join(',');
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
        if (!nbChoisies()) return;

        const corpsRequete = new URLSearchParams({
            tags: [...choisies.tags].join(','),
            genres: [...choisies.genres].join(','),
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
