-- Migration à appliquer sur une base existante.
--
-- mysql_init/ n'est rejoué par Docker que sur une base vierge ; ce fichier est
-- appliqué aux installations en service par docker/appliquer_migrations.sh.
--
-- Présence des utilisateurs : qui est en ligne, et qui écoute quoi.
--
-- Une ligne par compte, mise à jour à chaque battement du navigateur — jamais
-- d'insertion répétée, d'où la clé primaire sur user_id. L'ancienneté de
-- `vu-a` dit si la personne est encore là : au-delà de quelques battements
-- manqués, elle est considérée hors ligne. Rien à nettoyer, rien à purger.
--
-- `track_id` peut rester nul : en ligne sans écouter est un état normal, et
-- c'est justement ce qui distingue le point fixe de la vague.
--
-- ON DELETE CASCADE sur les deux liens : un compte supprimé ne laisse pas de
-- présence fantôme, un titre supprimé ne bloque pas la ligne de présence de
-- celui qui l'écoutait.

CREATE TABLE IF NOT EXISTS `presence` (
    `user_id`  int(11)   NOT NULL,
    `vu-a`     timestamp NOT NULL DEFAULT current_timestamp()
                         ON UPDATE current_timestamp(),
    `track_id` int(11)            DEFAULT NULL,
    `en_ecoute` tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`user_id`),
    KEY `presence_tracks_id_fk` (`track_id`),
    CONSTRAINT `presence_users_id_fk` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `presence_tracks_id_fk` FOREIGN KEY (`track_id`)
        REFERENCES `tracks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
