--
-- Structure de la base de démonstration d'Unison.
--
-- Générée depuis le schéma de l'application : la démonstration fait tourner
-- exactement le même code, sur une base entièrement distincte. Aucune donnée
-- personnelle n'y est atteignable, même en cas d'oubli de filtre dans une
-- requête de l'application.
--
-- Ce fichier ne crée que la structure ; les données sont dans donnees.sql.
--

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `artist__genre` (
  `artist_id` int(11) NOT NULL,
  `genre_id` int(11) NOT NULL,
  PRIMARY KEY (`artist_id`,`genre_id`),
  KEY `artist__style_styles_id_fk` (`genre_id`),
  CONSTRAINT `artist__genre_artists_id_fk` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `artist__style_styles_id_fk` FOREIGN KEY (`genre_id`) REFERENCES `genres` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `artist__track` (
  `track_id` int(11) NOT NULL,
  `artist_id` int(11) NOT NULL,
  UNIQUE KEY `track_id` (`track_id`,`artist_id`),
  KEY `artist__track_artists_id_fk` (`artist_id`),
  CONSTRAINT `artist__track_artists_id_fk` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `artist__track_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `artists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `img` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `artists_pk` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `genres` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `genres_pk` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `historical` (
  `listened-by_id` int(11) NOT NULL,
  `track_id` int(11) NOT NULL,
  `playlist_id` int(11) DEFAULT NULL,
  `listened-at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`listened-by_id`,`track_id`,`listened-at`),
  KEY `historical_playlists_id_fk` (`playlist_id`),
  KEY `track_id` (`track_id`),
  CONSTRAINT `historical_playlists_id_fk` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE SET NULL ON UPDATE SET NULL,
  CONSTRAINT `historical_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `historical_users_id_fk` FOREIGN KEY (`listened-by_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `nb_listen` (
  `user_id` int(11) NOT NULL,
  `track_id` int(11) NOT NULL,
  `nb` int(11) NOT NULL,
  UNIQUE KEY `nb_listen_track_id_user_id_uindex` (`track_id`,`user_id`),
  KEY `nb_listen_users_id_fk` (`user_id`),
  CONSTRAINT `nb_listen_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `nb_listen_users_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `note__playlist` (
  `note_id` int(11) NOT NULL,
  `playlist_id` int(11) NOT NULL,
  PRIMARY KEY (`note_id`,`playlist_id`),
  KEY `note__playlist_playlists_id_fk` (`playlist_id`),
  CONSTRAINT `note__playlist_notes_id_fk` FOREIGN KEY (`note_id`) REFERENCES `notes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `note__playlist_playlists_id_fk` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `note__track` (
  `note_id` int(11) NOT NULL,
  `track_id` int(11) NOT NULL,
  PRIMARY KEY (`note_id`,`track_id`),
  KEY `note__track_ibfk_1` (`track_id`),
  CONSTRAINT `note__track_notes_id_fk` FOREIGN KEY (`note_id`) REFERENCES `notes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `note__track_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `text` text NOT NULL,
  `created-by_id` int(11) NOT NULL,
  `created-at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `notes_users_id_fk` (`created-by_id`),
  CONSTRAINT `notes_users_id_fk` FOREIGN KEY (`created-by_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `playlists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `created-by_id` int(11) NOT NULL,
  `created-at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated-at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `playlists_users_id_fk` (`created-by_id`),
  CONSTRAINT `playlists_ibfk_1` FOREIGN KEY (`created-by_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `tag__playlist` (
  `tag_id` int(11) NOT NULL,
  `playlist_id` int(11) NOT NULL,
  PRIMARY KEY (`tag_id`,`playlist_id`),
  KEY `tag__playlist_playlists_id_fk` (`playlist_id`),
  CONSTRAINT `tag__playlist_playlists_id_fk` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `tag__playlist_tags_id_fk` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `tag__track` (
  `tag_id` int(11) NOT NULL,
  `track_id` int(11) NOT NULL,
  PRIMARY KEY (`track_id`,`tag_id`),
  KEY `tag__track_tags_id_fk` (`tag_id`),
  CONSTRAINT `tag__track_tags_id_fk` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `tag__track_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tags_name_uindex` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `track__genre` (
  `track_id` int(11) NOT NULL,
  `genre_id` int(11) NOT NULL,
  PRIMARY KEY (`track_id`,`genre_id`),
  KEY `track__genre_genres_id_fk` (`genre_id`),
  CONSTRAINT `track__genre_genres_id_fk` FOREIGN KEY (`genre_id`) REFERENCES `genres` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `track__genre_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `track__playlist` (
  `playlist_id` int(11) NOT NULL,
  `track_id` int(11) NOT NULL,
  `position` int(11) NOT NULL,
  `added-at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`playlist_id`,`track_id`),
  KEY `playlist__track_ibfk_1` (`track_id`),
  CONSTRAINT `track__playlist_playlists_id_fk` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `track__playlist_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `tracks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(50) NOT NULL,
  `duration` int(11) NOT NULL,
  `file` varchar(250) NOT NULL,
  `url` varchar(150) NOT NULL,
  `img` varchar(250) DEFAULT NULL,
  `created-at` timestamp NOT NULL DEFAULT current_timestamp(),
  `added-by_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracks_title_index` (`title`) USING BTREE,
  UNIQUE KEY `file` (`file`),
  UNIQUE KEY `url` (`url`),
  KEY `tracks_users_id_fk` (`added-by_id`),
  CONSTRAINT `tracks_users_id_fk` FOREIGN KEY (`added-by_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(50) DEFAULT NULL,
  `password-hash` varchar(250) DEFAULT NULL,
  `time-listened` int(11) NOT NULL DEFAULT 0,
  `view_mode` varchar(10) NOT NULL DEFAULT 'mixed',
  `reset_token` varchar(250) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'user',
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_pk` (`username`),
  UNIQUE KEY `users_pk_2` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
