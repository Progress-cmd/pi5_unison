/**
 * Présence du second compte du foyer, affichée dans son cercle de l'en-tête.
 *
 * L'initiale du prénom occupe le cercle en permanence ; l'état se lit à la
 * pastille posée dans son coin :
 *   hors ligne  aucune pastille
 *   en ligne    un point plein
 *   en écoute   trois barres animées, une vague
 *
 * Le battement sert aussi à annoncer le nôtre : un seul aller-retour fait les
 * deux, sans quoi chaque onglet enverrait deux requêtes là où une suffit.
 *
 * Règle qui gouverne tout le fichier : **c'est la lecture qui décide, pas la
 * visibilité de l'onglet**. Sur téléphone, l'écran se verrouille et l'onglet
 * passe en arrière-plan alors que la musique continue — c'est même tout
 * l'objet des notifications média. Un onglet caché qui joue est donc bien
 * présent, et bien en écoute.
 */
(function () {
    const cercle = document.getElementById('presence-partenaire');
    if (!cercle) return;   // hors foyer : aucun partenaire à afficher

    const point = cercle.querySelector('.presence-point');
    const vague = cercle.querySelector('.presence-vague');

    const INTERVALLE = 15000;

    /*
     * Onglet caché et silencieux : on continue d'observer, mais plus
     * lentement. Il n'annonce plus sa propre présence — sans quoi un onglet
     * oublié depuis trois jours afficherait un point vert en permanence — et
     * se contente de tenir son affichage à jour, pour ne pas le retrouver
     * figé au retour.
     */
    const INTERVALLE_CACHE = 60000;

    let minuteur = null;
    let cache = false;             // l'onglet est-il en arrière-plan ?
    let enDeconnexion = false;     // une déconnexion est-elle en cours ?

    /*
     * Dernier message déjà signalé. Sans cette mémoire, chaque battement
     * re-signalerait les mêmes non-lus : une notification toutes les quinze
     * secondes pour un message qu'on a déjà vu passer.
     */
    let dernierSignale = 0;
    let annonceAffichee = false;

    /** Un son sort-il vraiment de cette page, maintenant ? */
    function joue() {
        const audio = window.unisonAudio;
        return !!(audio && audio.src && !audio.paused && !audio.ended);
    }

    /*
     * Observer, c'est lire sans s'annoncer. Réservé à l'onglet caché qui ne
     * joue rien : dès qu'un son sort, la présence est réelle et doit être
     * publiée, écran allumé ou non.
     */
    function observeSeulement() {
        return cache && !joue();
    }

    /** Ce que le player est en train de faire, au moment du battement. */
    function monEtat() {
        const params = new URLSearchParams();

        // window.currentIndex et waitPlaylist sont posés par player.js ; on
        // lit l'élément audio à la source plutôt qu'un état recopié, qui
        // pourrait avoir dérivé.
        const enCours = window.waitPlaylist && window.waitPlaylist[window.currentIndex];

        if (enCours && enCours.id) params.set('track_id', String(enCours.id));
        params.set('en_ecoute', joue() ? '1' : '0');

        if (observeSeulement()) params.set('observer', '1');

        return params;
    }

    function afficher(etat) {
        const enLigne  = !!(etat && etat.en_ligne);
        const enEcoute = !!(etat && etat.en_ecoute);

        // L'initiale reste toujours visible : elle dit qui est ce cercle.
        // Seule la pastille d'état change.
        point.hidden = !enLigne || enEcoute;
        vague.hidden = !enEcoute;

        cercle.classList.toggle('est-en-ligne', enLigne);
        cercle.classList.toggle('est-en-ecoute', enEcoute);

        majLigneEcoute(etat, enEcoute);

        /*
         * L'infobulle reste, en complément de la ligne ci-dessus : au survol
         * d'un cercle, sur ordinateur, elle répond sans déplacer le regard.
         * Elle était en revanche le SEUL porteur de l'information, ce qui la
         * rendait inaccessible au doigt — un téléphone n'a pas de survol.
         */
        if (!etat) {
            cercle.title = '';
        } else if (enEcoute && etat.titre) {
            cercle.title = etat.username + ' écoute\n' + etat.titre
                         + (etat.artiste ? '\n' + etat.artiste : '');
        } else if (enEcoute) {
            cercle.title = etat.username + ' écoute';
        } else if (enLigne) {
            cercle.title = etat.username + ' est en ligne';
        } else {
            cercle.title = etat.username + ' est hors ligne';
        }
    }

    /*
     * « Voir ce qui est écouté » : replié par défaut.
     *
     * Ce que l'autre écoute ne s'affiche pas d'office — il faut le demander.
     * Mais le geste doit exister au doigt : c'est un appui, pas un survol.
     * L'infobulle du cercle, qui portait seule cette information, était
     * inatteignable sur téléphone.
     */
    const ligneEcoute   = document.getElementById('presence-ecoute');
    const bascule       = document.getElementById('presence-bascule');
    const basculeTexte  = document.getElementById('presence-bascule-texte');
    const ligneTexte    = document.getElementById('presence-ecoute-texte');
    let titreCourantId = null;

    /** Referme et remet le bouton dans son état d'invite. */
    function replier() {
        if (!ligneEcoute) return;
        ligneTexte.hidden = true;
        bascule.setAttribute('aria-expanded', 'false');
        ligneEcoute.classList.remove('ouvert');
    }

    function majLigneEcoute(etat, enEcoute) {
        if (!ligneEcoute) return;

        /*
         * Sans titre partagé, il n'y a rien à révéler : le bouton lui-même
         * disparaît. Le réglage « partager ce que j'écoute » vaut ici aussi.
         */
        if (!enEcoute || !etat || !etat.titre) {
            ligneEcoute.hidden = true;
            titreCourantId = null;
            replier();
            return;
        }

        const nouveau = (etat.track_id || null);

        /*
         * Un changement de titre referme le volet : laisser affiché l'ancien
         * nom pendant que l'autre écoute déjà autre chose serait pire que de
         * ne rien montrer.
         */
        if (nouveau !== titreCourantId) replier();

        titreCourantId = nouveau;

        // textContent : titre et artiste viennent de la base.
        ligneTexte.textContent = etat.username + ' écoute ' + etat.titre
                               + (etat.artiste ? ' · ' + etat.artiste : '');
        basculeTexte.textContent = 'Voir ce qu\'écoute ' + etat.username;
        bascule.setAttribute('aria-label', 'Voir ce qu\'écoute ' + etat.username);

        ligneTexte.disabled = !titreCourantId;
        ligneEcoute.hidden = false;
    }

    if (ligneEcoute) {
        bascule.addEventListener('click', (e) => {
            e.stopPropagation();
            const ouvre = ligneTexte.hidden;
            ligneTexte.hidden = !ouvre;
            bascule.setAttribute('aria-expanded', ouvre ? 'true' : 'false');
            ligneEcoute.classList.toggle('ouvert', ouvre);
        });

        // Le texte révélé mène à la fiche du titre.
        ligneTexte.addEventListener('click', () => {
            if (!titreCourantId) return;
            sessionStorage.setItem('titre_id', String(titreCourantId));
            navigateTo('library/titre');
            replier();
        });

        // Un clic ailleurs referme : le volet n'a pas à rester ouvert derrière
        // l'utilisateur une fois qu'il a lu.
        document.addEventListener('click', (e) => {
            if (!ligneEcoute.contains(e.target)) replier();
        });
    }

    async function battre() {
        // Déconnexion en cours : notre ligne est en train d'être supprimée,
        // écrire maintenant la ressusciterait. Voir `annoncerDepart`.
        if (enDeconnexion) return;

        try {
            const res = await fetch('actions/presence.php?' + monEtat());
            if (!res.ok) return;

            const data = await res.json();
            if (!data.success) return;

            const partenaire = (data.autres || [])
                .find(a => String(a.user_id) === cercle.dataset.userId);

            afficher(partenaire || null);
            traiterMessages(data.messages);
            traiterAnnonce(data.annonce);
        } catch (e) {
            /*
             * Réseau coupé ou serveur muet : on laisse l'affichage tel quel.
             * Basculer sur « hors ligne » au premier échec ferait clignoter le
             * cercle à chaque requête perdue, ce qui se lit comme une
             * information alors que ce n'en est pas une.
             */
        }
    }

    /* ---------- Messages ---------- */

    function traiterMessages(infos) {
        if (!infos) return;

        window.majPastilleMessages && window.majPastilleMessages(infos.non_lus);

        const dernier = infos.dernier;
        if (!dernier || dernier.id <= dernierSignale) return;

        /*
         * Au premier battement de la page, on ne signale rien : les messages
         * en attente depuis hier ne sont pas des arrivées, et les annoncer à
         * chaque ouverture serait exactement le harcèlement qu'on évite. La
         * pastille, elle, les montre.
         */
        const premierTour = dernierSignale === 0;
        dernierSignale = dernier.id;
        if (premierTour) return;

        /*
         * Déjà dans la conversation : elle affiche le message elle-même, et
         * le marque lu dans la foulée. On teste la présence du fil plutôt que
         * la route courante — le routeur ne l'expose pas, et l'élément dit la
         * même chose sans qu'on ait à le modifier.
         */
        if (document.getElementById('chat-fil')
            && document.visibilityState === 'visible') return;

        signaler(dernier.auteur, dernier.apercu);
    }

    /**
     * Toast ou notification système, selon le réglage du compte.
     *
     * La notification système exige un contexte sécurisé et une autorisation :
     * en HTTP sur une IP locale, `Notification` n'existe même pas. On retombe
     * alors sur le toast plutôt que de ne rien montrer.
     */
    function signaler(auteur, apercu) {
        const texte = auteur + ' : ' + apercu;

        if (window.UNISON_NOTIF === 'systeme'
            && typeof Notification !== 'undefined'
            && Notification.permission === 'granted') {
            try {
                new Notification('Unison', { body: texte, tag: 'unison-message' });
                return;
            } catch (e) {
                // Certains navigateurs mobiles refusent le constructeur hors
                // service worker : le toast reste.
            }
        }

        window.showToast && window.showToast(texte, 'success', 6000);
    }

    /* ---------- Annonces ---------- */

    function traiterAnnonce(annonce) {
        if (!annonce || annonceAffichee) return;
        annonceAffichee = true;
        afficherAnnonce(annonce);
    }

    /*
     * Popup d'annonce, construite par le DOM : le texte vient de l'interface
     * d'administration, il n'a rien à faire dans un innerHTML.
     */
    function afficherAnnonce(annonce) {
        const fond = document.createElement('div');
        fond.className = 'annonce-fond';

        const boite = document.createElement('div');
        boite.className = 'annonce-boite';
        boite.setAttribute('role', 'dialog');
        boite.setAttribute('aria-modal', 'true');

        const tete = document.createElement('div');
        tete.className = 'annonce-tete';

        const icone = document.createElement('span');
        icone.className = 'material-symbols-outlined';
        icone.textContent = annonce.type === 'fonctionnalite' ? 'auto_awesome' : 'campaign';

        const titre = document.createElement('h2');
        titre.textContent = annonce.titre;
        tete.append(icone, titre);

        const corps = document.createElement('div');
        corps.className = 'annonce-corps';
        // Les retours à la ligne saisis par l'administration sont conservés
        // (white-space: pre-line en CSS) : pas besoin de les convertir en HTML.
        corps.textContent = annonce.contenu;

        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = 'buttons infos-valider';
        bouton.textContent = "J'ai compris";

        boite.append(tete, corps, bouton);
        fond.appendChild(boite);
        document.body.appendChild(fond);

        async function fermer() {
            fond.remove();
            try {
                await fetch('actions/annonce_vue.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ annonce_id: String(annonce.id) }),
                });
            } catch (e) {
                /*
                 * Non marquée : elle reviendra au prochain chargement. C'est
                 * le bon sens du raté — mieux vaut la revoir une fois de trop
                 * que rater une annonce.
                 */
                annonceAffichee = false;
            }
        }

        bouton.addEventListener('click', fermer);
        bouton.focus();

        // Échap ferme aussi : une popup sans sortie au clavier est une prison.
        fond.addEventListener('keydown', (e) => { if (e.key === 'Escape') fermer(); });
    }

    function demarrer(intervalle) {
        arreter();
        battre();
        minuteur = setInterval(battre, intervalle || INTERVALLE);
    }

    function arreter() {
        clearInterval(minuteur);
        minuteur = null;
    }

    /*
     * Annonce de l'arrêt au moment de partir.
     *
     * `fetch` est abandonné quand la page se ferme : c'est exactement le cas
     * qu'on veut couvrir. `sendBeacon` est conçu pour cela — le navigateur se
     * charge de l'envoi après la fermeture. Il émet un POST, que l'action
     * accepte au même titre qu'un GET.
     */
    function annoncerDepart() {
        if (enDeconnexion) return;

        const params = monEtat();
        params.set('en_ecoute', '0');

        // Ce battement-ci doit écrire : c'est lui qui éteint la vague chez
        // l'autre. `monEtat()` a pu poser `observer`, on le retire.
        params.delete('observer');

        if (navigator.sendBeacon) {
            navigator.sendBeacon('actions/presence.php?' + params);
        } else {
            battre();
        }
    }

    /*
     * Un changement de lecture est annoncé tout de suite, y compris écran
     * éteint : c'est précisément là que l'autre a besoin de le savoir.
     */
    if (window.unisonAudio) {
        ['play', 'pause', 'ended'].forEach(function (evt) {
            window.unisonAudio.addEventListener(evt, function () {
                /*
                 * La cadence dépend de ce qu'on fait, pas seulement de la
                 * visibilité : un onglet caché qui se remet à jouer — depuis
                 * la notification du téléphone, par exemple — repasse au
                 * rythme normal, sinon il s'annoncerait une fois par minute
                 * et paraîtrait absent par intermittence.
                 */
                demarrer(observeSeulement() ? INTERVALLE_CACHE : INTERVALLE);
            });
        });
    }

    /*
     * Déconnexion : on se tait définitivement.
     *
     * logout.php supprime notre ligne de présence, mais la navigation vers
     * cette page déclenche d'abord `pagehide`. Sans ce garde-fou, le dernier
     * battement et la suppression partent en même temps : si le battement
     * arrive après, il recrée la ligne et l'autre nous voit encore là —
     * parfois même « en écoute », si la chanson tournait encore.
     */
    document.addEventListener('click', function (e) {
        if (!e.target.closest) return;
        if (!e.target.closest('a[href*="logout.php"]')) return;

        /*
         * En phase de bouillonnement, et seulement si le départ est confirmé.
         * Le lien porte un `confirm()` : en capture, on se tairait avant même
         * la question, et un « annuler » nous laisserait muets — donc vus
         * hors ligne — alors qu'on est toujours là.
         */
        if (e.defaultPrevented) return;

        enDeconnexion = true;
        arreter();
    });

    document.addEventListener('visibilitychange', () => {
        cache = document.visibilityState !== 'visible';

        if (!cache) {
            demarrer();
            return;
        }

        /*
         * Passage en arrière-plan.
         *
         * Si un son joue, rien ne change : la présence est réelle, on continue
         * de l'annoncer au rythme normal. Sinon on cesse de s'annoncer — mais
         * pas d'observer, sous peine de retrouver l'affichage figé au retour.
         */
        if (joue()) {
            demarrer();
            return;
        }

        annoncerDepart();
        demarrer(INTERVALLE_CACHE);
    });

    /*
     * Fermeture de l'onglet. `pagehide` est le dernier moment fiable sur
     * mobile, où `unload` ne se déclenche pas. Ici le son s'arrête vraiment
     * avec la page : on annonce l'arrêt quoi qu'il arrive.
     */
    window.addEventListener('pagehide', annoncerDepart);

    cache = document.visibilityState !== 'visible';
    demarrer(observeSeulement() ? INTERVALLE_CACHE : INTERVALLE);
})();
