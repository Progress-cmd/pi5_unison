/**
 * Terminal de la console d'administration.
 *
 * Reçoit de actions/admin/console.php une liste de blocs typés (texte, titre,
 * tableau, paires, erreur, succès) et les dessine. Le serveur décide de la
 * structure, ce fichier ne décide que de l'apparence.
 *
 * IMPORTANT — tout ce qui est affiché ici sort de la base : titres importés,
 * noms de fichiers, messages d'erreur de MariaDB. Rien n'est jamais posé en
 * innerHTML : chaque valeur passe par textContent. Un titre de morceau
 * contenant du HTML doit s'afficher, pas s'exécuter.
 */
(function () {
    /*
     * Le routeur réinjecte ce script à chaque navigation vers la page. La
     * fonction est donc idempotente : elle sort si l'ossature est absente, et
     * marque le terminal une fois branché pour ne pas doubler les écouteurs.
     */
    function demarrer() {
        const racine = document.getElementById('console');
        if (!racine || racine.dataset.branche) return;
        racine.dataset.branche = '1';

        const sortie = document.getElementById('console-sortie');
        const champ  = document.getElementById('console-commande');
        const invite = document.getElementById('console-invite');

        /** Commandes connues, pour la complétion par Tab. */
        const commandes = (racine.dataset.commandes || '').split(',').filter(Boolean);

        /*
         * Historique de session, comme dans un shell. Conservé en mémoire
         * seulement : il contient des requêtes SQL et n'a rien à faire dans le
         * stockage du navigateur.
         */
        const historique = [];
        let curseur = 0;          // position dans l'historique (longueur = ligne vierge)
        let enCours = false;      // une commande attend sa réponse

        /* ------------------------------------------------------------------
         * Dessin des blocs
         * --------------------------------------------------------------- */

        /** Ajoute un élément à la sortie et fait défiler jusqu'en bas. */
        function ajouter(element) {
            sortie.appendChild(element);
            sortie.scrollTop = sortie.scrollHeight;
        }

        /** Bloc de texte d'une classe donnée. */
        function bloc(classe, texte) {
            const div = document.createElement('div');
            div.className = 'console-bloc ' + classe;
            div.textContent = texte;
            return div;
        }

        /** Rappel de la commande saisie, façon invite de shell. */
        function echoCommande(texte) {
            const div = document.createElement('div');
            div.className = 'console-bloc console-echo';

            const prompt = document.createElement('span');
            prompt.className = 'console-echo-invite';
            prompt.textContent = invite.textContent.trim() + ' ';
            div.appendChild(prompt);

            const commande = document.createElement('span');
            commande.textContent = texte;
            div.appendChild(commande);

            return div;
        }

        /** Liste « clé : valeur ». */
        function blocPaires(paires) {
            const dl = document.createElement('dl');
            dl.className = 'console-bloc console-paires';

            for (const [cle, valeur] of paires) {
                const dt = document.createElement('dt');
                dt.textContent = cle;
                dl.appendChild(dt);

                const dd = document.createElement('dd');
                dd.textContent = valeur;
                // Les valeurs qui signalent un problème se repèrent d'un coup
                // d'œil : c'est tout l'intérêt de « sante ».
                if (/INJOIGNABLE|ABSENT|impossible/.test(valeur)) {
                    dd.className = 'console-valeur-alerte';
                }
                dl.appendChild(dd);
            }

            return dl;
        }

        /** Tableau à colonnes, défilable horizontalement s'il est large. */
        function blocTableau(colonnes, lignes) {
            const enveloppe = document.createElement('div');
            enveloppe.className = 'console-bloc console-tableau-enveloppe';

            const table = document.createElement('table');
            table.className = 'console-tableau';

            const thead = document.createElement('thead');
            const trTete = document.createElement('tr');
            for (const colonne of colonnes) {
                const th = document.createElement('th');
                th.textContent = colonne;
                trTete.appendChild(th);
            }
            thead.appendChild(trTete);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            for (const ligne of lignes) {
                const tr = document.createElement('tr');
                for (const cellule of ligne) {
                    const td = document.createElement('td');
                    td.textContent = cellule;
                    tr.appendChild(td);
                }
                tbody.appendChild(tr);
            }
            table.appendChild(tbody);

            enveloppe.appendChild(table);
            return enveloppe;
        }

        /** Aiguille un bloc reçu du serveur vers son rendu. */
        function dessiner(b) {
            switch (b.type) {
                case 'titre':   return bloc('console-titre', b.texte);
                case 'erreur':  return bloc('console-erreur', b.texte);
                case 'succes':  return bloc('console-succes', b.texte);
                case 'paires':  return blocPaires(b.paires);
                case 'tableau': return blocTableau(b.colonnes, b.lignes);
                case 'texte':
                default:        return bloc('console-texte', b.texte);
            }
        }

        /* ------------------------------------------------------------------
         * Exécution
         * --------------------------------------------------------------- */

        /** Jeton CSRF, déposé par la page dans un attribut de données. */
        function jeton() {
            const el = document.querySelector('[data-csrf]');
            return el ? el.dataset.csrf : '';
        }

        async function executer(ligne) {
            ajouter(echoCommande(ligne));

            /*
             * « effacer » est traitée ici et n'atteint jamais le serveur :
             * elle ne concerne que l'affichage. C'est la seule exception.
             */
            if (ligne.trim().toLowerCase() === 'effacer') {
                sortie.replaceChildren();
                return;
            }

            enCours = true;
            champ.disabled = true;

            const attente = bloc('console-texte console-attente', '…');
            ajouter(attente);

            try {
                const res = await fetch('actions/admin/console.php', {
                    method: 'POST',
                    body: new URLSearchParams({ commande: ligne, token: jeton() }),
                });

                attente.remove();

                // 404 = exigerAdmin() a refusé : la session n'est plus admin.
                if (res.status === 404) {
                    ajouter(bloc('console-erreur',
                        'Session non administratrice — reconnectez-vous.'));
                    return;
                }

                const data = await res.json();

                for (const b of (data.blocs || [])) {
                    ajouter(dessiner(b));
                }

                // L'invite rappelle en permanence la base interrogée : sans ça,
                // on oublie qu'on est resté sur la démonstration.
                if (data.base) {
                    invite.textContent = 'unison:' + data.base + ' $';
                }
            } catch (e) {
                attente.remove();
                ajouter(bloc('console-erreur', 'Erreur réseau — commande non exécutée.'));
            } finally {
                enCours = false;
                champ.disabled = false;
                champ.focus();
            }
        }

        /* ------------------------------------------------------------------
         * Saisie
         * --------------------------------------------------------------- */

        /**
         * Complète le début d'une commande.
         * Si plusieurs commandes commencent pareil, on complète jusqu'au
         * préfixe commun et on affiche les candidates — comportement d'un shell.
         */
        function completer() {
            const saisie = champ.value;

            // La complétion ne porte que sur le premier mot : au-delà, les
            // arguments sont des titres ou du SQL, qu'on ne peut pas deviner.
            if (saisie.includes(' ')) return;

            const candidates = commandes.filter(c => c.startsWith(saisie.toLowerCase()));

            if (candidates.length === 0) return;

            if (candidates.length === 1) {
                champ.value = candidates[0] + ' ';
                return;
            }

            // Plus long préfixe commun aux candidates.
            let prefixe = candidates[0];
            for (const candidate of candidates) {
                while (!candidate.startsWith(prefixe)) {
                    prefixe = prefixe.slice(0, -1);
                }
            }

            champ.value = prefixe;
            ajouter(bloc('console-texte console-candidates', candidates.join('   ')));
        }

        champ.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (enCours) return;

                const ligne = champ.value.trim();
                if (ligne === '') return;

                // Pas de doublon consécutif dans l'historique, comme un shell.
                if (historique[historique.length - 1] !== ligne) {
                    historique.push(ligne);
                }
                curseur = historique.length;

                champ.value = '';
                executer(ligne);
                return;
            }

            if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (curseur > 0) {
                    curseur--;
                    champ.value = historique[curseur];
                    // Curseur en fin de ligne, sinon il reste au début.
                    setTimeout(() => champ.setSelectionRange(champ.value.length, champ.value.length), 0);
                }
                return;
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (curseur < historique.length - 1) {
                    curseur++;
                    champ.value = historique[curseur];
                } else {
                    // Retour à la ligne vierge, en bas de l'historique.
                    curseur = historique.length;
                    champ.value = '';
                }
                return;
            }

            if (e.key === 'Tab') {
                e.preventDefault();
                completer();
                return;
            }

            if (e.key === 'Escape') {
                e.preventDefault();
                champ.value = '';
            }
        });

        // Cliquer n'importe où dans le terminal ramène le curseur à la saisie,
        // sauf si on est en train de sélectionner du texte pour le copier.
        racine.addEventListener('click', () => {
            if (!window.getSelection().toString()) champ.focus();
        });

        const btnEffacer = document.getElementById('console-effacer');
        if (btnEffacer) {
            btnEffacer.addEventListener('click', () => {
                sortie.replaceChildren();
                champ.focus();
            });
        }

        champ.focus();
    }

    window.ConsoleUnison = { demarrer };
    demarrer();
})();
