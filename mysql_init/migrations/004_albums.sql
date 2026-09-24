-- Migration à appliquer sur une base existante.
--
-- mysql_init/ n'est rejoué par Docker que sur une base vierge ; ce fichier est
-- appliqué aux installations en service par docker/appliquer_migrations.sh.
--
-- Introduit les albums.
--
-- Deux colonnes distinctes sont ajoutées à `tracks`, et cette distinction est
-- le cœur de la fonctionnalité :
--
--   album_id         l'album auquel le titre APPARTIENT, tel qu'affiché.
--                    Se détache (SET NULL) si l'album disparaît.
--
--   album_source_id  l'album qui a FAIT ENTRER le titre dans la discothèque.
--                    C'est lui, et lui seul, qui autorise la suppression du
--                    fichier avec l'album.
--
-- Sans cette seconde colonne, supprimer un album emporterait des titres qui
-- existaient avant son import — un morceau déjà présent, puis rattaché parce
-- qu'une compilation le contenait, serait détruit avec elle. La source dit
-- « c'est cet import qui l'a amené », et rien d'autre ne peut le dire après
-- coup.

CREATE TABLE IF NOT EXISTS `albums` (
    `id`            int(11)      NOT NULL AUTO_INCREMENT,
    `title`         varchar(150) NOT NULL,
    `artist_id`     int(11)               DEFAULT NULL,
    `annee`         int(11)               DEFAULT NULL,
    `img`           varchar(250)          DEFAULT NULL,
    -- Provenance de l'import : l'URL de la playlist YouTube. Permet de
    -- reconnaître un album déjà importé, et de le réimporter à l'identique.
    `source_url`    varchar(250)          DEFAULT NULL,
    `added-by_id`   int(11)      NOT NULL,
    `created-at`    timestamp    NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `albums_source_url` (`source_url`),
    KEY `albums_title_index` (`title`),
    KEY `albums_artists_id_fk` (`artist_id`),
    KEY `albums_users_id_fk` (`added-by_id`),
    CONSTRAINT `albums_artists_id_fk` FOREIGN KEY (`artist_id`)
        REFERENCES `artists` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `albums_users_id_fk` FOREIGN KEY (`added-by_id`)
        REFERENCES `users` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `tracks`
    ADD COLUMN IF NOT EXISTS `album_id` int(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `album_source_id` int(11) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `track_number` int(11) DEFAULT NULL;

-- Les deux liens s'effacent d'eux-mêmes si l'album disparaît : aucune ligne de
-- `tracks` ne doit jamais pointer sur un album supprimé. Ce que devient le
-- FICHIER est décidé par le code, qui lit album_source_id AVANT de supprimer.
ALTER TABLE `tracks`
    ADD KEY IF NOT EXISTS `tracks_album_id_fk` (`album_id`),
    ADD KEY IF NOT EXISTS `tracks_album_source_id_fk` (`album_source_id`);

ALTER TABLE `tracks`
    ADD CONSTRAINT `tracks_album_id_fk` FOREIGN KEY (`album_id`)
        REFERENCES `albums` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `tracks`
    ADD CONSTRAINT `tracks_album_source_id_fk` FOREIGN KEY (`album_source_id`)
        REFERENCES `albums` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
