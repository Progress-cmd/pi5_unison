/**
 * Composant terminal de la section d'administration.
 *
 * Sert deux pages qui ne diffèrent que par leur point d'entrée :
 *   · Console  — commandes nommées   (actions/admin/console.php)
 *   · SQL      — requêtes libres      (actions/admin/sql.php)
 *
 * Tout ce qui change entre les deux est déclaré en attributs de données sur
 * l'élément racine (« data-endpoint », « data-commandes ») ; le serveur renvoie
 * l'invite à afficher. Ce fichier ne connaît donc aucune commande, et ajouter
 * une page de terminal ne demande pas d'y toucher.
 *
 * Le serveur répond par une liste de blocs typés (texte, titre, tableau,
 * paires, erreur, succès) que ce script dessine.
 *
 * IMPORTANT — tout ce qui s'affiche ici sort de la base : titres importés,
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

        /** Point d'entrée serveur, propre à la page. */
        const endpoint = racine.dataset.endpoint || 'actions/admin/console.php';

        /** Mots connus, pour la complétion par Tab (facultatif). */
        const motsConnus = (racine.dataset.commandes || '').split(',').filter(Boolean);

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

        function ajouter(element) {
            sortie.appendChild(element);
            sortie.scrollTop = sortie.scrollHeight;
        }

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
                    // NULL se distingue d'une chaîne vide, comme dans un client SQL.
                    if (cellule === '∅') td.className = 'console-nul';
                    tr.appendChild(td);
                }
                tbody.appendChild(tr);
            }
            table.appendChild(tbody);

            enveloppe.appendChild(table);
            return enveloppe;
        }

        function dessiner(b) {
            switch (b.type) {
                case 'titre':   return bloc('console-titre', b.texte);
                case 'erreur':  return bloc('console-erreur', b.texte);
                case 'succes':  return bloc('console-succes', b.texte);
                case 'alerte':  return bloc('console-alerte', b.texte);
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
             * « effacer » ne concerne que l'affichage : traitée ici, elle
             * n'atteint jamais le serveur. C'est la seule exception.
             */
            if (/^\\?effacer$/i.test(ligne.trim())) {
                sortie.replaceChildren();
                return;
            }

            enCours = true;
            champ.disabled = true;

            const attente = bloc('console-texte console-attente', '…');
            ajouter(attente);

            try {
                const res = await fetch(endpoint, {
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

                /*
                 * L'invite est calculée par le serveur : elle rappelle la base
                 * interrogée et, pour le terminal SQL, si le mode écriture est
                 * actif. C'est le seul rappel permanent de cet état.
                 */
                if (data.invite) {
                    invite.textContent = data.invite;
                    racine.classList.toggle('console-mode-ecriture', !!data.ecriture);
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

        /** La zone de saisie grandit avec son contenu, jusqu'à une limite. */
        function ajusterHauteur() {
            champ.style.height = 'auto';
            champ.style.height = Math.min(champ.scrollHeight, 200) + 'px';
        }

        /**
         * Complète le premier mot. Si plusieurs candidats commencent pareil, on
         * complète jusqu'au préfixe commun et on les affiche — comme un shell.
         */
        function completer() {
            const saisie = champ.value;

            // Au-delà du premier mot, les arguments sont des titres ou du SQL :
            // rien qui puisse se deviner.
            if (!motsConnus.length || /\s/.test(saisie)) return;

            const candidats = motsConnus.filter(c => c.startsWith(saisie.toLowerCase()));
            if (candidats.length === 0) return;

            if (candidats.length === 1) {
                champ.value = candidats[0] + ' ';
                ajusterHauteur();
                return;
            }

            let prefixe = candidats[0];
            for (const candidat of candidats) {
                while (!candidat.startsWith(prefixe)) {
                    prefixe = prefixe.slice(0, -1);
                }
            }

            champ.value = prefixe;
            ajusterHauteur();
            ajouter(bloc('console-texte console-candidates', candidats.join('   ')));
        }

        /** Le curseur est-il sur la première / dernière ligne de la saisie ? */
        function surPremiereLigne() {
            return !champ.value.slice(0, champ.selectionStart).includes('\n');
        }

        function surDerniereLigne() {
            return !champ.value.slice(champ.selectionEnd).includes('\n');
        }

        function rappeler(texte) {
            champ.value = texte;
            ajusterHauteur();
            // Curseur en fin de saisie, sinon il resterait au début.
            setTimeout(() => champ.setSelectionRange(champ.value.length, champ.value.length), 0);
        }

        champ.addEventListener('input', ajusterHauteur);

        champ.addEventListener('keydown', (e) => {
            // Entrée envoie ; Maj+Entrée passe à la ligne — une requête SQL
            // un peu longue se lit beaucoup mieux sur plusieurs lignes.
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (enCours) return;

                const ligne = champ.value.trim();
                if (ligne === '') return;

                if (historique[historique.length - 1] !== ligne) {
                    historique.push(ligne);
                }
                curseur = historique.length;

                champ.value = '';
                ajusterHauteur();
                executer(ligne);
                return;
            }

            /*
             * ↑ et ↓ ne rappellent l'historique que depuis le bord de la
             * saisie : au milieu d'une requête sur plusieurs lignes, elles
             * doivent déplacer le curseur, pas effacer le travail en cours.
             */
            if (e.key === 'ArrowUp' && surPremiereLigne()) {
                e.preventDefault();
                if (curseur > 0) {
                    curseur--;
                    rappeler(historique[curseur]);
                }
                return;
            }

            if (e.key === 'ArrowDown' && surDerniereLigne()) {
                e.preventDefault();
                if (curseur < historique.length - 1) {
                    curseur++;
                    rappeler(historique[curseur]);
                } else {
                    curseur = historique.length;
                    rappeler('');
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
                ajusterHauteur();
            }
        });

        // Cliquer dans le terminal ramène le curseur à la saisie, sauf si on
        // est en train de sélectionner du texte pour le copier.
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

        ajusterHauteur();
        champ.focus();
    }

    window.ConsoleUnison = { demarrer };
    demarrer();
})();
