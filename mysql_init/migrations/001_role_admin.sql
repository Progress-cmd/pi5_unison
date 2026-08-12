-- Migration à appliquer sur une base existante.
--
-- mysql_init/ n'est rejoué par Docker que sur une base vierge : ce fichier
-- doit être lancé à la main sur une installation déjà en service.
--
--   cd docker && set -a && . ./.env && set +a && cd ..
--   docker compose -f docker/docker-compose-dev.yml exec -T db \
--       mariadb -u root -p"$DB_ROOTPASS" "$DB_NAME" < mysql_init/migrations/001_role_admin.sql
--
-- Ajoute le rôle des comptes. Valeurs utilisées par l'application :
--   'user'      : compte normal (défaut)
--   'admin'     : accès à la section d'administration
--   'desactive' : compte conservé mais dont la connexion est refusée
--
-- Le compte d'administration n'est PAS créé ici : son mot de passe ne doit pas
-- être versionné. Utiliser src/includes/creerAdmin.php.

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `role` varchar(20) NOT NULL DEFAULT 'user';
