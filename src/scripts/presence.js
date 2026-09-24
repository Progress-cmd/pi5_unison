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

        // L'infobulle porte le détail : le cercle dit l'état, le survol dit
        // quoi. Y écrire le titre évite une zone d'affichage supplémentaire
        // dans un en-tête déjà chargé.
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
        } catch (e) {
            /*
             * Réseau coupé ou serveur muet : on laisse l'affichage tel quel.
             * Basculer sur « hors ligne » au premier échec ferait clignoter le
             * cercle à chaque requête perdue, ce qui se lit comme une
             * information alors que ce n'en est pas une.
             */
        }
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
