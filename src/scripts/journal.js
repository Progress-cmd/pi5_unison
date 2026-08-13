/**
 * Page Journal de la section d'administration.
 *
 * Le tableau est rempli ici plutôt que par PHP : changer un filtre ne doit pas
 * recharger la page entière, et le rafraîchissement automatique n'est possible
 * que de cette façon.
 *
 * IMPORTANT — le journal contient du texte d'origine extérieure : nom de
 * fichier importé, clé de connexion reçue, message d'exception. Rien n'est
 * jamais posé en innerHTML dans ce fichier : tout passe par textContent ou par
 * des nœuds créés à la main. Une trace d'attaque ne doit pas devenir une
 * attaque au moment où on la consulte.
 */
(function () {
    /*
     * Le routeur réinjecte ce script à chaque navigation. On ne se protège
     * donc pas d'une seconde exécution — on la rend inoffensive : la fonction
     * ne fait rien si son ossature est absente, et marque le tableau une fois
     * branché pour ne pas doubler les écouteurs.
     */
    function demarrer() {
        const corps = document.getElementById('journal-corps');
        if (!corps || corps.dataset.branche) return;
        corps.dataset.branche = '1';

        const champs = {
            niveau:    document.getElementById('filtre-niveau'),
            canal:     document.getElementById('filtre-canal'),
            heures:    document.getElementById('filtre-heures'),
            recherche: document.getElementById('filtre-recherche'),
        };

        const compteur   = document.getElementById('journal-compteur');
        const libellePage = document.getElementById('journal-page');
        const btnPrec    = document.getElementById('journal-precedent');
        const btnSuiv    = document.getElementById('journal-suivant');
        const btnAuto    = document.getElementById('journal-auto');

        let page = 1;
        let pages = 1;
        let minuterie = null;

        /** Paramètres d'URL correspondant à l'état courant des filtres. */
        function parametres() {
            const p = new URLSearchParams({ page: String(page) });

            for (const [nom, champ] of Object.entries(champs)) {
                const valeur = champ && champ.value.trim();
                if (valeur) p.set(nom, valeur);
            }

            return p;
        }

        /** Une cellule de tableau contenant du texte brut. */
        function cellule(texte, classe) {
            const td = document.createElement('td');
            td.textContent = texte === null || texte === undefined ? '' : String(texte);
            if (classe) td.className = classe;
            return td;
        }

        /**
         * Ligne d'événement. Le contexte JSON, quand il existe, est replié
         * dans un <details> : il est indispensable au diagnostic mais illisible
         * s'il est affiché en permanence sur toutes les lignes.
         */
        function ligne(ev) {
            const tr = document.createElement('tr');
            tr.className = 'journal-ligne niveau-' + ev.niveau;

            const tdQuand = cellule(ev.quand);
            tdQuand.title = ev.horodatage;      // date exacte au survol
            tr.appendChild(tdQuand);

            const tdNiveau = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = 'journal-niveau ' + ev.niveau;
            badge.textContent = ev.niveau;
            tdNiveau.appendChild(badge);
            tr.appendChild(tdNiveau);

            tr.appendChild(cellule(ev.canal_libelle));

            // --- Événement : message, code d'action, contexte repliable ---
            const tdEvenement = document.createElement('td');
            tdEvenement.className = 'principal';

            const message = document.createElement('div');
            message.className = 'journal-message';
            message.textContent = ev.message || ev.action;
            tdEvenement.appendChild(message);

            const meta = document.createElement('div');
            meta.className = 'journal-meta';
            meta.textContent = ev.action
                + (ev.chemin ? ' · ' + ev.chemin : '')
                + (ev.duree_ms !== null && ev.duree_ms !== undefined ? ' · ' + ev.duree_ms + ' ms' : '');
            tdEvenement.appendChild(meta);

            if (ev.contexte) {
                const details = document.createElement('details');
                details.className = 'journal-contexte';

                const resume = document.createElement('summary');
                resume.textContent = 'Contexte';
                details.appendChild(resume);

                const pre = document.createElement('pre');
                pre.textContent = JSON.stringify(ev.contexte, null, 2);
                details.appendChild(pre);

                tdEvenement.appendChild(details);
            }

            tr.appendChild(tdEvenement);

            // --- Compte : nom, puis IP en seconde ligne ---
            const tdCompte = document.createElement('td');
            tdCompte.textContent = ev.utilisateur || '—';
            if (ev.ip) {
                const ip = document.createElement('div');
                ip.className = 'journal-meta';
                ip.textContent = ev.ip;
                tdCompte.appendChild(ip);
            }
            tr.appendChild(tdCompte);

            return tr;
        }

        /** Remplace le contenu du tableau par une seule ligne de message. */
        function messageSeul(texte) {
            corps.replaceChildren();
            const tr = document.createElement('tr');
            const td = cellule(texte, 'admin-vide');
            td.colSpan = 5;
            tr.appendChild(td);
            corps.appendChild(tr);
        }

        /** Charge la page courante et redessine le tableau. */
        async function charger() {
            try {
                const res = await fetch('actions/admin/journal.php?' + parametres());

                // 404 = exigerAdmin() a refusé : la session n'est plus admin.
                if (res.status === 404) {
                    arreterAuto();
                    messageSeul('Session non administratrice — reconnectez-vous');
                    return;
                }

                const data = await res.json();

                if (!data.success) {
                    messageSeul(data.message || 'Lecture du journal impossible');
                    return;
                }

                pages = data.pages;
                compteur.textContent = data.total + ' événement(s)';
                libellePage.textContent = 'page ' + data.page + ' / ' + data.pages;
                btnPrec.disabled = data.page <= 1;
                btnSuiv.disabled = data.page >= data.pages;

                if (data.evenements.length === 0) {
                    messageSeul('Aucun événement ne correspond à ces filtres');
                    return;
                }

                // replaceChildren plutôt qu'innerHTML = '' : les anciens nœuds
                // partent avec leurs écouteurs, sans réanalyse de HTML.
                corps.replaceChildren(...data.evenements.map(ligne));
            } catch (e) {
                messageSeul('Erreur réseau — journal indisponible');
            }
        }

        /** Retour à la première page : tout changement de filtre la réinitialise. */
        function rechargerDepuisLeDebut() {
            page = 1;
            charger();
        }

        for (const champ of Object.values(champs)) {
            if (!champ) continue;
            // « change » pour les listes, « input » pour la recherche au fil
            // de la frappe — temporisée, pour ne pas requêter à chaque touche.
            champ.addEventListener('change', rechargerDepuisLeDebut);
        }

        if (champs.recherche) {
            let attente = null;
            champs.recherche.addEventListener('input', () => {
                clearTimeout(attente);
                attente = setTimeout(rechargerDepuisLeDebut, 350);
            });
        }

        btnPrec.addEventListener('click', () => {
            if (page > 1) { page--; charger(); }
        });

        btnSuiv.addEventListener('click', () => {
            if (page < pages) { page++; charger(); }
        });

        document.getElementById('journal-rafraichir').addEventListener('click', charger);

        document.getElementById('journal-reinitialiser').addEventListener('click', () => {
            for (const champ of Object.values(champs)) {
                if (champ) champ.value = '';
            }
            rechargerDepuisLeDebut();
        });

        /* --- Rafraîchissement automatique --- */

        function arreterAuto() {
            if (minuterie) {
                clearInterval(minuterie);
                minuterie = null;
            }
            if (btnAuto) btnAuto.checked = false;
        }

        btnAuto.addEventListener('change', () => {
            if (btnAuto.checked) {
                // 10 s : assez pour suivre une opération en cours, assez lent
                // pour ne pas interroger la base sans raison.
                minuterie = setInterval(charger, 10000);
                charger();
            } else {
                arreterAuto();
            }
        });

        /*
         * Le routeur remplace le contenu de #main-content sans prévenir : sans
         * ça, la minuterie continuerait de tourner sur un tableau détaché,
         * indéfiniment. On surveille la disparition du tableau pour l'arrêter.
         */
        const observateur = new MutationObserver(() => {
            if (!document.body.contains(corps)) {
                arreterAuto();
                observateur.disconnect();
            }
        });
        observateur.observe(document.getElementById('main-content') || document.body,
            { childList: true, subtree: true });

        /* --- Purge --- */

        const btnPurger = document.getElementById('journal-purger');
        if (btnPurger) {
            btnPurger.addEventListener('click', async () => {
                const A = window.AdminUnison;
                if (!A) return;

                const saisie = prompt(
                    'Purge du journal.\n\n' +
                    'Les événements plus vieux que ce nombre de jours seront supprimés ' +
                    'définitivement.\n\nNombre de jours à conserver :',
                    '90'
                );
                if (saisie === null) return;

                const jours = parseInt(saisie, 10);
                if (!Number.isInteger(jours) || jours < 1) {
                    window.showToast && window.showToast(
                        'Indiquez un nombre de jours valide (au moins 1)', 'error');
                    return;
                }

                const r = await A.appeler('purger_journal.php', { jours: String(jours) });
                if (r && r.success) rechargerDepuisLeDebut();
            });
        }

        charger();
    }

    window.JournalUnison = { demarrer };
    demarrer();
})();
