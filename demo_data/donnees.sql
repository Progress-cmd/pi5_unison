--
-- Contenu de la base de démonstration d'Unison.
--
-- Tout est fictif : les artistes, les titres, les playlists et les notes sont
-- inventés pour la vitrine. Les fichiers audio associés sont générés par
-- installer.sh (nappes de synthèse produites avec ffmpeg), donc libres de
-- droits par construction : rien de ce catalogue n'appartient à un tiers.
--
-- Rejouable : le contenu est vidé avant d'être réinséré.
--

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `historical`;
TRUNCATE TABLE `nb_listen`;
TRUNCATE TABLE `note__playlist`;
TRUNCATE TABLE `note__track`;
TRUNCATE TABLE `notes`;
TRUNCATE TABLE `tag__playlist`;
TRUNCATE TABLE `tag__track`;
TRUNCATE TABLE `tags`;
TRUNCATE TABLE `track__genre`;
TRUNCATE TABLE `track__playlist`;
TRUNCATE TABLE `artist__genre`;
TRUNCATE TABLE `artist__track`;
TRUNCATE TABLE `genres`;
TRUNCATE TABLE `playlists`;
TRUNCATE TABLE `tracks`;
TRUNCATE TABLE `artists`;
TRUNCATE TABLE `users`;

--
-- Comptes de la vitrine.
-- Le hash est volontairement vide : on ne se connecte jamais à ces comptes par
-- mot de passe, seul le bouton « Découvrir la démo » y donne accès, et
-- actions/login.php refuse un hash vide.
--
-- Le rôle est explicite : aucun compte d'administration n'existe dans la base
-- de démonstration, même si un jour le défaut de la colonne changeait.
INSERT INTO `users` (`id`, `username`, `email`, `password-hash`, `time-listened`, `view_mode`, `role`) VALUES
(1, 'Alex',  NULL, '', 48120, 'mixed', 'user'),
(2, 'Robin', NULL, '', 31540, 'mixed', 'user');

--
-- Artistes fictifs.
--
INSERT INTO `artists` (`id`, `name`, `img`) VALUES
(1, 'Marée Basse',        NULL),
(2, 'Colline & Sombre',   NULL),
(3, 'Atelier Nocturne',   NULL),
(4, 'Petite Fugue',       NULL),
(5, 'Vera Lindqvist',     NULL),
(6, 'Le Théorème du Lin', NULL),
(7, 'Halo Bleu',          NULL),
(8, 'Kaolin',             NULL);

--
-- Genres.
--
INSERT INTO `genres` (`id`, `name`) VALUES
(1, 'Ambient'),
(2, 'Néo-classique'),
(3, 'Folk'),
(4, 'Électronique'),
(5, 'Jazz');

--
-- Étiquettes, avec leurs descriptions.
--
INSERT INTO `tags` (`id`, `name`, `description`) VALUES
(1, 'Matin',      'Pour démarrer doucement, sans réveiller personne.'),
(2, 'Concentré',  'Sans paroles, pour tenir une longue session de travail.'),
(3, 'Pluie',      'Ce qu''on met quand il tombe des cordes.'),
(4, 'Voiture',    'Les titres qui passent bien sur la route.'),
(5, 'Découverte', 'Repéré récemment, à réécouter.');

--
-- Titres. La durée est en secondes ; elle correspond aux fichiers générés.
-- La colonne url porte un index unique : on y met une référence interne,
-- puisque ces morceaux ne viennent d'aucune plateforme.
--
INSERT INTO `tracks` (`id`, `title`, `duration`, `file`, `url`, `img`, `added-by_id`) VALUES
(1,  'Quai Nord',            42, 'demo_01.mp3', 'demo:01', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23F2EAE1''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%23C8593A''/></svg>', 1),
(2,  'Lettre à Marta',       42, 'demo_02.mp3', 'demo:02', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23E6EDF2''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%234A7C99''/></svg>', 1),
(3,  'Sous les tuiles',      42, 'demo_03.mp3', 'demo:03', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23EDE8F2''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%236B5B95''/></svg>', 2),
(4,  'Petit Matin Gris',     42, 'demo_04.mp3', 'demo:04', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23E9F2EA''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%234C8B5B''/></svg>', 1),
(5,  'Vitres embuées',       42, 'demo_05.mp3', 'demo:05', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23F2EFE1''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%23B08A3E''/></svg>', 2),
(6,  'Chambre 12',           42, 'demo_06.mp3', 'demo:06', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23F2E4E4''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%23A65959''/></svg>', 1),
(7,  'Le Dernier Métro',     42, 'demo_07.mp3', 'demo:07', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23F2EAE1''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%23C8593A''/></svg>', 2),
(8,  'Fjord',                42, 'demo_08.mp3', 'demo:08', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23E6EDF2''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%234A7C99''/></svg>', 1),
(9,  'Papier calque',        42, 'demo_09.mp3', 'demo:09', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23EDE8F2''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%236B5B95''/></svg>', 2),
(10, 'Neige de Mars',        42, 'demo_10.mp3', 'demo:10', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23E9F2EA''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%234C8B5B''/></svg>', 1),
(11, 'Corde à linge',        42, 'demo_11.mp3', 'demo:11', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23F2EFE1''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%23B08A3E''/></svg>', 2),
(12, 'Août sans personne',   42, 'demo_12.mp3', 'demo:12', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23F2E4E4''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%23A65959''/></svg>', 1),
(13, 'Bleu de travail',      42, 'demo_13.mp3', 'demo:13', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23F2EAE1''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%23C8593A''/></svg>', 2),
(14, 'Sentier des Douanes',  42, 'demo_14.mp3', 'demo:14', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23E6EDF2''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%234A7C99''/></svg>', 1),
(15, 'Rideau de fer',        42, 'demo_15.mp3', 'demo:15', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23EDE8F2''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%236B5B95''/></svg>', 2),
(16, 'Une heure de plus',    42, 'demo_16.mp3', 'demo:16', 'data:image/svg+xml,<svg%20xmlns=''http://www.w3.org/2000/svg''%20viewBox=''0%200%2064%2064''><rect%20width=''64''%20height=''64''%20fill=''%23E9F2EA''/><circle%20cx=''32''%20cy=''32''%20r=''7''%20fill=''%234C8B5B''/></svg>', 1);

--
-- Rattachement des titres aux artistes.
--
INSERT INTO `artist__track` (`track_id`, `artist_id`) VALUES
(1, 1), (2, 5), (3, 2), (4, 4), (5, 3), (6, 7), (7, 6), (8, 5),
(9, 8), (10, 1), (11, 4), (12, 3), (13, 6), (14, 2), (15, 7), (16, 8),
-- Un duo, pour montrer l'affichage à plusieurs artistes
(12, 5);

--
-- Genres des titres.
--
INSERT INTO `track__genre` (`track_id`, `genre_id`) VALUES
(1, 1), (2, 2), (3, 3), (4, 2), (5, 1), (6, 4), (7, 5), (8, 1),
(9, 4), (10, 1), (11, 3), (12, 2), (13, 5), (14, 3), (15, 4), (16, 2);

--
-- Genres des artistes.
--
INSERT INTO `artist__genre` (`artist_id`, `genre_id`) VALUES
(1, 1), (2, 3), (3, 1), (4, 2), (5, 2), (6, 5), (7, 4), (8, 4);

--
-- Étiquettes des titres.
--
INSERT INTO `tag__track` (`tag_id`, `track_id`) VALUES
(1, 4), (1, 10), (2, 1), (2, 5), (2, 8), (2, 12), (3, 3), (3, 5),
(4, 7), (4, 15), (5, 9), (5, 16);

--
-- Playlists.
-- Les quatre premières reproduisent les listes système créées par
-- l'application pour chaque compte (attente et favoris).
--
INSERT INTO `playlists` (`id`, `name`, `created-by_id`) VALUES
(1, 'Wait Tracks',        1),
(2, 'Favorite Tracks',    1),
(3, 'Wait Tracks',        2),
(4, 'Favorite Tracks',    2),
(5, 'Dimanche lent',      1),
(6, 'Route de nuit',      2),
(7, 'Bureau, volume bas', 1),
(8, 'À faire écouter',    2);

--
-- Contenu des playlists.
--
INSERT INTO `track__playlist` (`playlist_id`, `track_id`, `position`) VALUES
-- File d'attente d'Alex : c'est elle que le player charge à l'ouverture.
-- Sans elle, la démonstration s'ouvrirait sans rien à lire.
(1, 1, 0), (1, 4, 1), (1, 8, 2), (1, 10, 3), (1, 16, 4), (1, 12, 5),
-- File d'attente de Robin
(3, 7, 0), (3, 15, 1), (3, 13, 2), (3, 9, 3),
-- Favoris d'Alex
(2, 1, 0), (2, 8, 1), (2, 10, 2), (2, 14, 3),
-- Favoris de Robin
(4, 7, 0), (4, 13, 1), (4, 15, 2),
-- Dimanche lent
(5, 4, 0), (5, 2, 1), (5, 11, 2), (5, 16, 3), (5, 3, 4),
-- Route de nuit
(6, 7, 0), (6, 15, 1), (6, 6, 2), (6, 9, 3),
-- Bureau, volume bas
(7, 1, 0), (7, 5, 1), (7, 8, 2), (7, 12, 3), (7, 10, 4), (7, 16, 5),
-- À faire écouter
(8, 9, 0), (8, 13, 1), (8, 14, 2);

--
-- Étiquettes des playlists.
--
INSERT INTO `tag__playlist` (`tag_id`, `playlist_id`) VALUES
(1, 5), (2, 7), (3, 5), (4, 6), (5, 8);

--
-- Notes : elles illustrent la fonctionnalité de commentaire partagé.
--
INSERT INTO `notes` (`id`, `text`, `created-by_id`) VALUES
(1, 'La montée à 1:10 est la meilleure partie du morceau, ne pas zapper.', 1),
(2, 'Trouvé un dimanche pluvieux, ça colle parfaitement à la saison.', 2),
(3, 'À écouter au casque, il y a plein de détails au fond du mixage.', 1),
(4, 'Playlist montée pour le trajet de retour, l''ordre compte.', 2),
(5, 'Trois titres qui se répondent bien, à garder groupés.', 1),
(6, 'Version un peu trop longue à mon goût, mais la fin rattrape tout.', 2);

INSERT INTO `note__track` (`note_id`, `track_id`) VALUES
(1, 8), (2, 3), (3, 12), (6, 7);

INSERT INTO `note__playlist` (`note_id`, `playlist_id`) VALUES
(4, 6), (5, 8);

--
-- Quelques compteurs d'écoute, pour que l'accueil et les statistiques
-- n'apparaissent pas vides.
--
INSERT INTO `nb_listen` (`user_id`, `track_id`, `nb`) VALUES
(1, 1, 34), (1, 8, 27), (1, 10, 22), (1, 4, 19), (1, 14, 15), (1, 12, 11),
(1, 5, 9),  (1, 16, 6), (1, 2, 4),
(2, 7, 41), (2, 15, 25), (2, 13, 18), (2, 9, 14), (2, 6, 12), (2, 3, 8),
(2, 11, 5), (2, 1, 3);

SET FOREIGN_KEY_CHECKS = 1;
