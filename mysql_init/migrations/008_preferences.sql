-- Migration à appliquer sur une base existante.
--
-- mysql_init/ n'est rejoué par Docker que sur une base vierge ; ce fichier est
-- appliqué aux installations en service par docker/appliquer_migrations.sh.
--
-- Préférences de compte, pour la page Paramètres.
--
-- Trois réglages qui doivent suivre la personne d'un appareil à l'autre, donc
-- stockés en base plutôt qu'en localStorage :
--
--   presence_visible        apparaître en ligne, ou rester discret
--   presence_partage_titre  laisser voir le titre écouté
--   theme                   clair / sombre / systeme
--
-- Les réglages de lecture (volume mémorisé, reprise) restent volontairement
-- côté navigateur : ils dépendent de l'appareil, pas de la personne — le
-- volume du téléphone n'a rien à voir avec celui du PC.
--
-- Valeurs par défaut choisies pour ne rien changer au comportement actuel :
-- la présence est déjà visible et partagée, le thème suit le système.

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `presence_visible` tinyint(1) NOT NULL DEFAULT 1
        AFTER `view_mode`,
    ADD COLUMN IF NOT EXISTS `presence_partage_titre` tinyint(1) NOT NULL DEFAULT 1
        AFTER `presence_visible`,
    ADD COLUMN IF NOT EXISTS `theme` varchar(10) NOT NULL DEFAULT 'systeme'
        AFTER `presence_partage_titre`;
