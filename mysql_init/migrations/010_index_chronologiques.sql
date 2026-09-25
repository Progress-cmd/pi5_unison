-- Migration à appliquer sur une base existante.
--
-- mysql_init/ n'est rejoué par Docker que sur une base vierge ; ce fichier est
-- appliqué aux installations en service par docker/appliquer_migrations.sh.
--
-- Index chronologiques, pour les pages qui lisent « du plus récent au plus
-- ancien » : fil d'activité et historique d'écoute.
--
-- Sans eux, MariaDB devait trier la table entière avant d'appliquer la limite.
-- Mesuré sur un jeu de 3 000 titres, 20 000 messages et 30 000 écoutes :
-- l'historique demandait 56 ms pour rendre trente lignes, et le fil 29 ms.
-- Le coût d'un index se paie à l'insertion, quelques dizaines de fois par
-- jour ici ; le tri se payait à chaque affichage.

ALTER TABLE `tracks`
    ADD INDEX IF NOT EXISTS `tracks_chrono` (`created-at`);

ALTER TABLE `albums`
    ADD INDEX IF NOT EXISTS `albums_chrono` (`created-at`);

ALTER TABLE `playlists`
    ADD INDEX IF NOT EXISTS `playlists_chrono` (`created-at`);

ALTER TABLE `notes`
    ADD INDEX IF NOT EXISTS `notes_chrono` (`created-at`);

ALTER TABLE `artist__favorite`
    ADD INDEX IF NOT EXISTS `artist_favorite_chrono` (`created-at`);

-- La clé primaire est (listened-by_id, track_id, listened-at) : elle ne sait
-- pas rendre l'historique d'une personne dans l'ordre du temps, parce que
-- track_id s'intercale. Cet index-ci le sait.
ALTER TABLE `historical`
    ADD INDEX IF NOT EXISTS `historical_chrono` (`listened-by_id`, `listened-at`);
