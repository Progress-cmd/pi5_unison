-- Migration à appliquer sur une base existante.
--
-- mysql_init/ n'est rejoué par Docker que sur une base vierge ; ce fichier est
-- appliqué aux installations en service par docker/appliquer_migrations.sh.
--
-- Déconnexion des autres appareils.
--
-- Les sessions PHP sont des fichiers dans /tmp, sans aucun lien avec le compte
-- qu'elles portent : rien ne permet de retrouver « les sessions de Francis »
-- sans parcourir et désérialiser tout le dossier — coûteux, fragile, et
-- dépendant d'un détail de configuration qui peut changer.
--
-- D'où ce jeton, stocké en base et recopié dans la session à la connexion. Les
-- deux sont comparés à chaque requête : le régénérer invalide d'un coup toutes
-- les sessions existantes, y compris celles d'appareils qu'on n'a plus sous la
-- main. La session qui déclenche l'opération recopie le nouveau jeton et reste
-- donc valide.
--
-- Il sert aussi de filet après un changement de mot de passe : un mot de passe
-- changé parce qu'on le croit compromis doit fermer les sessions ouvertes
-- ailleurs, sinon le changement ne protège de rien.

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `jeton_session` varchar(64) DEFAULT NULL;
