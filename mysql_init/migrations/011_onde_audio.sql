-- Migration à appliquer sur une base existante.
--
-- mysql_init/ n'est rejoué par Docker que sur une base vierge ; ce fichier est
-- appliqué aux installations en service par docker/appliquer_migrations.sh.
--
-- Forme d'onde du morceau, pour la barre de progression.
--
-- Calculée une fois à l'import avec ffmpeg (191 ms par titre mesuré), puis
-- relue telle quelle : l'alternative, un analyseur Web Audio dans le
-- navigateur, ne montre que l'instant présent, ne sait rien dire en pause, et
-- fait tourner une boucle d'animation en continu sur le téléphone.
--
-- Stockage : 120 amplitudes de 0 à 255, encodées en base64 — 160 caractères
-- environ. Un tableau de nombres en texte aurait pesé trois fois plus pour la
-- même chose, et cette colonne est lue à chaque changement de piste.
--
-- NULL est un état normal : titre importé avant cette migration, ou fichier
-- que ffmpeg n'a pas su lire. La barre reprend alors son apparence unie.

ALTER TABLE `tracks`
    ADD COLUMN IF NOT EXISTS `onde` varchar(255) DEFAULT NULL AFTER `duration`;
