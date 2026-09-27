/*
 * Gestes partagés sur un titre : le ranger dans une playlist, l'envoyer dans
 * la liste d'attente. Utilisé par tout ce qui manipule un titre — le lecteur
 * et le menu « … » d'une ligne — pour qu'un même libellé fasse partout la
 * même chose.
 *
 * Il remplace deux gestes qui ne disaient pas la même chose. Le lecteur
 * ouvrait une liste de boutons : cliquer ajoutait, sans jamais montrer où le
 * titre se trouvait déjà, et sans permettre de l'enlever. La recherche, elle,
 * offrait un « + » d'une quinzaine de pixels dont l'état ne se lisait qu'au
 * survol. Des cases à cocher donnent les deux informations d'un coup — où le
 * titre est, où il n'est pas — et rendent le geste réversible.
 *
 * Le champ de recherche n'est pas décoratif : au-delà d'une dizaine de
 * playlists, parcourir la liste coûte plus cher que taper trois lettres.
 */
(function () {
    'use strict';

    /*
     * Fabrique de modales, remontée ici depuis player.js.
     *
     * Elle y était enfermée dans une IIFE alors que trois appelants en ont
     * besoin. Exposée sur window, elle reste le seul exemplaire de la
     * mécanique (fond, fermeture au clic hors-cadre, Échap).
     */
    window.ouvrirModale = function (titre) {
        const modale = document.createElement('div');
        modale.className = 'modale';

        const contenu = document.createElement('div');
        contenu.className = 'modale-contenu';

        const entete = document.createElement('div');
        entete.className = 'modale-titre';
        entete.textContent = titre;
        contenu.appendChild(entete);

        modale.appendChild(contenu);

        const fermer = () => {
            modale.remove();
            document.removeEventListener('keydown', surTouche);
        };

        modale.addEventListener('click', (e) => {
            if (e.target === modale) fermer();
        });

        // Échap ferme aussi : une modale qui ne se ferme qu'au clic piège
        // l'utilisateur au clavier.
        function surTouche(e) {
            if (e.key === 'Escape') fermer();
        }
        document.addEventListener('keydown', surTouche);

        document.body.appendChild(modale);
        return { modale, contenu, fermer };
    };

    /** « 12 titres » / « 1 titre ». */
    function pluriel(n) {
        return n + (n > 1 ? ' titres' : ' titre');
    }

    /**
     * Ajoute ou retire le titre d'une playlist.
     *
     * Renvoie true si l'opération a abouti : l'appelant remet la case dans son
     * état d'origine sinon. Sans ce retour, une coche restait affichée après
     * un échec réseau et l'utilisateur croyait le titre rangé.
     */
    async function basculer(trackId, playlistId, ajouter) {
        const url = ajouter
            ? 'actions/add_to_playlist.php'
            : 'actions/remove_track_from_playlist.php';

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    track_id: String(trackId),
                    playlist_id: String(playlistId),
                }),
            });
            const data = await res.json();
            if (!data.success) {
                window.showToast(data.message || 'Opération impossible', 'error');
                return false;
            }
            return true;
        } catch (e) {
            window.showToast('Erreur réseau', 'error');
            return false;
        }
    }

    /**
     * Envoie un titre dans la liste d'attente.
     *
     * @param {number|string} trackId
     * @param {'fin'|'suivant'} mode  en queue de file, ou juste après le titre
     *                                en cours de lecture.
     */
    window.fileAjouter = async function (trackId, mode) {
        if (!trackId) {
            window.showToast('Aucun titre sélectionné', 'error');
            return;
        }

        const corps = new URLSearchParams({ track_id: String(trackId), mode });

        /*
         * Le serveur ne peut pas deviner où on en est de la file : il range
         * « juste après » relativement au titre que le lecteur joue, et c'est
         * le client qui le connaît. Sans lui, l'insertion se ferait en tête.
         */
        const enCours = window.waitPlaylist && window.waitPlaylist[window.currentIndex];
        if (mode === 'suivant' && enCours) {
            corps.append('apres_track_id', String(enCours.id));
        }

        try {
            const res = await fetch('actions/file_ajouter.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: corps,
            });
            const data = await res.json();

            if (!data.success) {
                window.showToast(data.message || 'Opération impossible', 'error');
                return;
            }

            /*
             * La file revient avec la réponse : on remplace l'état du lecteur
             * plutôt que de recharger. Il faut retrouver l'index du titre en
             * cours dans la nouvelle file — les positions ont bougé, et garder
             * l'ancien index ferait sauter la lecture au mauvais morceau à la
             * piste suivante.
             */
            window.waitPlaylist = data.queue;
            if (enCours) {
                const i = data.queue.findIndex(t => String(t.id) === String(enCours.id));
                if (i !== -1) window.currentIndex = i;
            }

            const corpsFile = document.querySelector('#queue-bar .body-bar');
            if (corpsFile && typeof window.remplirLignesTitres === 'function') {
                window.remplirLignesTitres(corpsFile, data.queue, {
                    file: true,
                    badge: true,
                    messageVide: "File d'attente vide",
                });
            }

            window.showToast(data.message);
        } catch (e) {
            window.showToast('Erreur réseau', 'error');
        }
    };

    /**
     * Ouvre le sélecteur pour un titre.
     *
     * @param {number|string} trackId
     * @param {string}        [nomTitre] affiché en sous-titre, pour qu'on sache
     *                                   de quel titre il s'agit quand la
     *                                   modale s'ouvre depuis une longue liste.
     */
    window.ouvrirAjoutPlaylist = async function (trackId, nomTitre) {
        if (!trackId) {
            window.showToast('Aucun titre sélectionné', 'error');
            return;
        }

        let playlists;
        try {
            const res = await fetch('actions/get_playlists.php?track_id=' + encodeURIComponent(trackId));
            const data = await res.json();
            if (!data.success) {
                window.showToast(data.message || 'Impossible de charger les playlists', 'error');
                return;
            }
            playlists = data.playlists;
        } catch (e) {
            window.showToast('Erreur réseau', 'error');
            return;
        }

        const { contenu, fermer } = window.ouvrirModale('Ajouter à une playlist');
        const cadre = document.createElement('div');
        cadre.className = 'ajout-pl';

        if (nomTitre) {
            const sous = document.createElement('div');
            sous.className = 'ajout-pl-titre';
            // textContent : un titre de morceau vient de la base.
            sous.textContent = nomTitre;
            cadre.appendChild(sous);
        }

        if (!playlists.length) {
            const vide = document.createElement('p');
            vide.className = 'ajout-pl-vide';
            vide.textContent = "Vous n'avez aucune playlist pour l'instant.";
            cadre.appendChild(vide);
        } else {
            // Le champ de recherche ne s'affiche que s'il sert : sous six
            // playlists, la liste entière tient sous les yeux.
            let recherche = null;
            if (playlists.length > 6) {
                recherche = document.createElement('input');
                recherche.type = 'search';
                recherche.className = 'ajout-pl-recherche';
                recherche.placeholder = 'Rechercher une playlist…';
                recherche.autocomplete = 'off';
                cadre.appendChild(recherche);
            }

            const liste = document.createElement('div');
            liste.className = 'ajout-pl-liste';
            cadre.appendChild(liste);

            const aucune = document.createElement('p');
            aucune.className = 'ajout-pl-vide';
            aucune.textContent = 'Aucune playlist à ce nom.';
            aucune.hidden = true;
            cadre.appendChild(aucune);

            const lignes = playlists.map((p) => {
                const ligne = document.createElement('label');
                ligne.className = 'ajout-pl-ligne';

                const case_ = document.createElement('input');
                case_.type = 'checkbox';
                case_.checked = p.contient;

                const nom = document.createElement('span');
                nom.className = 'ajout-pl-nom';
                nom.textContent = p.nom;

                const compte = document.createElement('span');
                compte.className = 'ajout-pl-compte';
                compte.textContent = pluriel(p.nb_titres);

                ligne.append(case_, nom, compte);

                case_.addEventListener('change', async () => {
                    const ajouter = case_.checked;
                    case_.disabled = true;

                    const ok = await basculer(trackId, p.id, ajouter);
                    case_.disabled = false;

                    if (!ok) {
                        case_.checked = !ajouter;
                        return;
                    }

                    p.nb_titres += ajouter ? 1 : -1;
                    compte.textContent = pluriel(Math.max(0, p.nb_titres));
                    window.showToast(
                        ajouter ? 'Ajouté à « ' + p.nom + ' »' : 'Retiré de « ' + p.nom + ' »'
                    );

                    /*
                     * La page affichée peut être celle de cette playlist ou
                     * celle des favoris : elle montrerait un contenu périmé.
                     * On ne recharge pas — le routeur rejoue la page, ce qui
                     * garde la modale et le lecteur en place.
                     */
                    if (typeof window.rafraichirPageCourante === 'function') {
                        window.rafraichirPageCourante();
                    }
                });

                liste.appendChild(ligne);
                return { ligne, nom: p.nom.toLowerCase() };
            });

            if (recherche) {
                recherche.addEventListener('input', () => {
                    const q = recherche.value.trim().toLowerCase();
                    let visibles = 0;
                    lignes.forEach(({ ligne, nom }) => {
                        const garde = !q || nom.includes(q);
                        ligne.hidden = !garde;
                        if (garde) visibles++;
                    });
                    aucune.hidden = visibles > 0;
                });
            }
        }

        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = 'modale-fermer';
        bouton.textContent = 'Fermer';
        bouton.addEventListener('click', fermer);
        cadre.appendChild(bouton);

        contenu.appendChild(cadre);

        const premier = cadre.querySelector('.ajout-pl-recherche, input[type="checkbox"]');
        if (premier) premier.focus();
    };
})();
