-- Migration à appliquer sur une base existante.
--
-- mysql_init/ n'est rejoué par Docker que sur une base vierge ; ce fichier est
-- appliqué aux installations en service par docker/appliquer_migrations.sh.
--
-- Retire l'unicité du titre des morceaux.
--
-- `tracks.title` portait un UNIQUE KEY : deux morceaux ne pouvaient donc pas
-- porter le même nom, dans TOUTE la discothèque. C'est une contrainte
-- intenable pour de la musique — reprises, versions live, et les titres
-- simplement répandus (« Intro », « Home », « Alive »).
--
-- Le cas qui l'a révélée : « Somewhere Only We Know » de Keane empêchait
-- d'importer la reprise de Lily Allen. Le formulaire d'import refusait
-- d'ailleurs le second morceau avant même d'essayer — il ne faisait que
-- reproduire cette contrainte.
--
-- L'identité d'un morceau reste garantie par ailleurs : `file` et `url`
-- gardent leur UNIQUE KEY, et ce sont eux qui désignent réellement la vidéo
-- d'origine. Le titre redevient un simple index, conservé pour les recherches
-- (voir la commande « titres » de la console, qui filtre dessus).

ALTER TABLE `tracks` DROP INDEX IF EXISTS `tracks_title_index`;

CREATE INDEX IF NOT EXISTS `tracks_title_index` ON `tracks` (`title`);
