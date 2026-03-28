-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : jeu. 22 jan. 2026 à 10:01
-- Version du serveur : 10.4.32-MariaDB
-- Version de PHP : 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `cassetfran_bdd`
--

-- --------------------------------------------------------

--
-- Structure de la table `artists`
--

CREATE TABLE `artists` (
                           `id` int(11) NOT NULL,
                           `name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `artist__style`
--

CREATE TABLE `artist__style` (
                                 `artist_id` int(11) NOT NULL,
                                 `genre_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `artist__track`
--

CREATE TABLE `artist__track` (
                                 `track_id` int(11) NOT NULL,
                                 `artist_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `genres`
--

CREATE TABLE `genres` (
                          `id` int(11) NOT NULL,
                          `name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `historical`
--

CREATE TABLE `historical` (
                              `listened-by_id` int(11) NOT NULL,
                              `track_id` int(11) NOT NULL,
                              `playlist_id` int(11) DEFAULT NULL,
                              `listened-at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `nb_listen`
--

CREATE TABLE `nb_listen` (
                             `user_id` int(11) NOT NULL,
                             `track_id` int(11) NOT NULL,
                             `nb` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `notes`
--

CREATE TABLE `notes` (
                         `id` int(11) NOT NULL,
                         `text` text NOT NULL,
                         `created-by_id` int(11) NOT NULL,
                         `created-at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `note__playlist`
--

CREATE TABLE `note__playlist` (
                                  `note_id` int(11) NOT NULL,
                                  `playlist_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `note__track`
--

CREATE TABLE `note__track` (
                               `note_id` int(11) NOT NULL,
                               `track_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `playlists`
--

CREATE TABLE `playlists` (
                             `id` int(11) NOT NULL,
                             `name` varchar(50) NOT NULL,
                             `created-by_id` int(11) NOT NULL,
                             `created-at` timestamp NOT NULL DEFAULT current_timestamp(),
                             `updated-at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `tags`
--

CREATE TABLE `tags` (
                        `id` int(11) NOT NULL,
                        `name` varchar(50) NOT NULL,
                        `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `tag__playlist`
--

CREATE TABLE `tag__playlist` (
                                 `tag_id` int(11) NOT NULL,
                                 `playlist_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `tag__track`
--

CREATE TABLE `tag__track` (
                              `tag_id` int(11) NOT NULL,
                              `track_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `tracks`
--

CREATE TABLE `tracks` (
                          `id` int(11) NOT NULL,
                          `title` varchar(50) NOT NULL,
                          `duration` int(11) NOT NULL,
                          `file` varchar(250) NOT NULL,
                          `url` varchar(150) NOT NULL,
                          `img` varchar(250) DEFAULT NULL,
                          `created-at` timestamp NOT NULL DEFAULT current_timestamp(),
                          `added-by_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `track__genre`
--

CREATE TABLE `track__genre` (
                                `track_id` int(11) NOT NULL,
                                `genre_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `track__playlist`
--

CREATE TABLE `track__playlist` (
                                   `playlist_id` int(11) NOT NULL,
                                   `track_id` int(11) NOT NULL,
                                   `position` int(11) NOT NULL,
                                   `added-at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
                         `id` int(11) NOT NULL,
                         `username` varchar(50) NOT NULL,
                         `email` varchar(50) DEFAULT NULL,
                         `password-hash` varchar(250) DEFAULT NULL,
                         `time-listened` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Déchargement des données de la table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password-hash`, `time-listened`) VALUES
                                                                                      (1, 'Franfran', 'haspotfrancis@gmail.com', '$2y$10$xTncNVVzdi3jw7rvHoIBtOYolWAm6PEHn8HlKVuvCs/W6fLquACEa', 0),
                                                                                      (2, 'Cassous', 'cassandrejosso@gmail.com', NULL, 0);

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `artists`
--
ALTER TABLE `artists`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `artists_pk` (`name`);

--
-- Index pour la table `artist__style`
--
ALTER TABLE `artist__style`
    ADD PRIMARY KEY (`artist_id`,`genre_id`),
  ADD KEY `artist__style_styles_id_fk` (`genre_id`);

--
-- Index pour la table `artist__track`
--
ALTER TABLE `artist__track`
    ADD UNIQUE KEY `track_id` (`track_id`,`artist_id`),
    ADD KEY `artist__track_artists_id_fk` (`artist_id`);

--
-- Index pour la table `genres`
--
ALTER TABLE `genres`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `genres_pk` (`name`);

--
-- Index pour la table `historical`
--
ALTER TABLE `historical`
    ADD PRIMARY KEY (`listened-by_id`,`track_id`,`listened-at`),
  ADD KEY `historical_playlists_id_fk` (`playlist_id`),
  ADD KEY `track_id` (`track_id`);

--
-- Index pour la table `nb_listen`
--
ALTER TABLE `nb_listen`
    ADD UNIQUE KEY `nb_listen_track_id_user_id_uindex` (`track_id`,`user_id`),
    ADD KEY `nb_listen_users_id_fk` (`user_id`);

--
-- Index pour la table `notes`
--
ALTER TABLE `notes`
    ADD PRIMARY KEY (`id`),
  ADD KEY `notes_users_id_fk` (`created-by_id`);

--
-- Index pour la table `note__playlist`
--
ALTER TABLE `note__playlist`
    ADD PRIMARY KEY (`note_id`,`playlist_id`),
  ADD KEY `note__playlist_playlists_id_fk` (`playlist_id`);

--
-- Index pour la table `note__track`
--
ALTER TABLE `note__track`
    ADD PRIMARY KEY (`note_id`,`track_id`),
  ADD KEY `note__track_ibfk_1` (`track_id`);

--
-- Index pour la table `playlists`
--
ALTER TABLE `playlists`
    ADD PRIMARY KEY (`id`),
  ADD KEY `playlists_users_id_fk` (`created-by_id`);

--
-- Index pour la table `tags`
--
ALTER TABLE `tags`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tags_name_uindex` (`name`);

--
-- Index pour la table `tag__playlist`
--
ALTER TABLE `tag__playlist`
    ADD PRIMARY KEY (`tag_id`,`playlist_id`),
  ADD KEY `tag__playlist_playlists_id_fk` (`playlist_id`);

--
-- Index pour la table `tag__track`
--
ALTER TABLE `tag__track`
    ADD PRIMARY KEY (`track_id`,`tag_id`),
  ADD KEY `tag__track_tags_id_fk` (`tag_id`);

--
-- Index pour la table `tracks`
--
ALTER TABLE `tracks`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tracks_title_index` (`title`) USING BTREE,
  ADD UNIQUE KEY `file` (`file`),
  ADD UNIQUE KEY `url` (`url`),
  ADD KEY `tracks_users_id_fk` (`added-by_id`);

--
-- Index pour la table `track__genre`
--
ALTER TABLE `track__genre`
    ADD PRIMARY KEY (`track_id`,`genre_id`),
  ADD KEY `track__genre_genres_id_fk` (`genre_id`);

--
-- Index pour la table `track__playlist`
--
ALTER TABLE `track__playlist`
    ADD PRIMARY KEY (`playlist_id`,`track_id`),
  ADD KEY `playlist__track_ibfk_1` (`track_id`);

--
-- Index pour la table `users`
--
ALTER TABLE `users`
    ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_pk` (`username`),
  ADD UNIQUE KEY `users_pk_2` (`email`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `artists`
--
ALTER TABLE `artists`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `genres`
--
ALTER TABLE `genres`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `notes`
--
ALTER TABLE `notes`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `playlists`
--
ALTER TABLE `playlists`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `tags`
--
ALTER TABLE `tags`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `tracks`
--
ALTER TABLE `tracks`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `artist__style`
--
ALTER TABLE `artist__style`
    ADD CONSTRAINT `artist__style_artists_id_fk` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `artist__style_styles_id_fk` FOREIGN KEY (`genre_id`) REFERENCES `genres` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `artist__track`
--
ALTER TABLE `artist__track`
    ADD CONSTRAINT `artist__track_artists_id_fk` FOREIGN KEY (`artist_id`) REFERENCES `artists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `artist__track_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `historical`
--
ALTER TABLE `historical`
    ADD CONSTRAINT `historical_playlists_id_fk` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE SET NULL ON UPDATE SET NULL,
  ADD CONSTRAINT `historical_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `historical_users_id_fk` FOREIGN KEY (`listened-by_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION;

--
-- Contraintes pour la table `nb_listen`
--
ALTER TABLE `nb_listen`
    ADD CONSTRAINT `nb_listen_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `nb_listen_users_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `notes`
--
ALTER TABLE `notes`
    ADD CONSTRAINT `notes_users_id_fk` FOREIGN KEY (`created-by_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `note__playlist`
--
ALTER TABLE `note__playlist`
    ADD CONSTRAINT `note__playlist_notes_id_fk` FOREIGN KEY (`note_id`) REFERENCES `notes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `note__playlist_playlists_id_fk` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `note__track`
--
ALTER TABLE `note__track`
    ADD CONSTRAINT `note__track_notes_id_fk` FOREIGN KEY (`note_id`) REFERENCES `notes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `note__track_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `playlists`
--
ALTER TABLE `playlists`
    ADD CONSTRAINT `playlists_ibfk_1` FOREIGN KEY (`created-by_id`) REFERENCES `users` (`id`);

--
-- Contraintes pour la table `tag__playlist`
--
ALTER TABLE `tag__playlist`
    ADD CONSTRAINT `tag__playlist_playlists_id_fk` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `tag__playlist_tags_id_fk` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `tag__track`
--
ALTER TABLE `tag__track`
    ADD CONSTRAINT `tag__track_tags_id_fk` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `tag__track_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `tracks`
--
ALTER TABLE `tracks`
    ADD CONSTRAINT `tracks_users_id_fk` FOREIGN KEY (`added-by_id`) REFERENCES `users` (`id`);

--
-- Contraintes pour la table `track__genre`
--
ALTER TABLE `track__genre`
    ADD CONSTRAINT `track__genre_genres_id_fk` FOREIGN KEY (`genre_id`) REFERENCES `genres` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `track__genre_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `track__playlist`
--
ALTER TABLE `track__playlist`
    ADD CONSTRAINT `track__playlist_playlists_id_fk` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `track__playlist_tracks_id_fk` FOREIGN KEY (`track_id`) REFERENCES `tracks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
