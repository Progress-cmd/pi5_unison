-- Migration à appliquer sur une base existante.
--
-- mysql_init/ n'est rejoué par Docker que sur une base vierge ; ce fichier est
-- appliqué aux installations en service par docker/appliquer_migrations.sh.
--
-- Artistes favoris.
--
-- Une table de liaison, et non une playlist système comme pour les titres
-- favoris : une playlist contient des titres, pas des artistes. Le modèle
-- suit donc artist__track, qui dit déjà « cet artiste est lié à cette
-- chose ».
--
-- La clé primaire porte sur le couple : un même artiste ne peut pas être mis
-- deux fois en favori par le même compte, et la bascule s'écrit alors sans
-- garde applicative — INSERT échoue ou DELETE ne trouve rien, c'est tout.
--
-- Les deux suppressions en cascade sont volontaires : un artiste effacé ne
-- doit pas laisser de favori orphelin, un compte supprimé non plus.

CREATE TABLE IF NOT EXISTS `artist__favorite` (
    `user_id`    int(11)   NOT NULL,
    `artist_id`  int(11)   NOT NULL,
    `created-at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`user_id`, `artist_id`),
    KEY `artist__favorite_artists_id_fk` (`artist_id`),
    CONSTRAINT `artist__favorite_users_id_fk` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `artist__favorite_artists_id_fk` FOREIGN KEY (`artist_id`)
        REFERENCES `artists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
