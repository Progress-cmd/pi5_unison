-- Playlists partagées entre les comptes du foyer.
--
-- Jusqu'ici une playlist n'était visible et modifiable que par son auteur :
-- `get_playlists.php` filtrait sur `created-by_id`, et toute écriture passait
-- par `exigerPlaylistDeLUtilisateur()`. On ouvre désormais l'AJOUT aux autres
-- comptes — retirer un titre, renommer ou supprimer restent réservés au
-- propriétaire.
--
-- D'où cette colonne : sans elle, rien ne distinguerait un titre ajouté par le
-- propriétaire d'un titre ajouté par quelqu'un d'autre, et l'interface ne
-- pourrait pas le signaler.
--
-- NULL = ajouté avant cette migration, ou par le propriétaire lui-même sans
-- que ce soit noté. L'affichage traite NULL comme « le propriétaire ».
ALTER TABLE `track__playlist`
    ADD COLUMN IF NOT EXISTS `added-by_id` INT(11) NULL DEFAULT NULL AFTER `position`;

-- Sert à l'affichage du sélecteur, qui demande l'auteur de chaque ligne.
CREATE INDEX IF NOT EXISTS `idx_tp_added_by` ON `track__playlist` (`added-by_id`);
