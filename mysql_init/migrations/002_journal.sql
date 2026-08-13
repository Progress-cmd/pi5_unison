-- Migration à appliquer sur une base existante.
--
-- mysql_init/ n'est rejoué par Docker que sur une base vierge : ce fichier
-- doit être lancé à la main sur une installation déjà en service.
--
--   cd docker && set -a && . ./.env && set +a && cd ..
--   docker compose -f docker/docker-compose-dev.yml exec -T db \
--       mariadb -u root -p"$DB_ROOTPASS" "$DB_NAME" < mysql_init/migrations/002_journal.sql
--
-- Journal d'activité et d'incidents. Une ligne = un événement notable :
-- connexion, opération d'administration, importation, erreur PHP.
--
-- Cette table ne vit QUE dans la base principale. La démonstration travaille
-- sur une base séparée, mais son activité est journalisée ici quand même
-- (voir Config::getConnectionPrincipale) : sinon les traces d'une session de
-- démonstration seraient invisibles depuis l'administration, qui n'ouvre
-- jamais la base de démonstration.

CREATE TABLE IF NOT EXISTS `journal` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,

  -- Milliseconde : deux événements d'une même requête doivent rester
  -- ordonnables entre eux, ce qu'une précision à la seconde ne garantit pas.
  `horodatage` datetime(3) NOT NULL DEFAULT current_timestamp(3),

  -- debug | info | attention | erreur | critique (voir includes/journal.php)
  `niveau` varchar(10) NOT NULL DEFAULT 'info',

  -- auth | admin | contenu | import | stockage | recherche | console | systeme
  `canal` varchar(20) NOT NULL DEFAULT 'systeme',

  -- Code court et stable de l'événement : « connexion_reussie », « titre_supprime ».
  -- C'est lui qu'on filtre ; `message` n'est que la phrase lisible.
  `action` varchar(50) NOT NULL,
  `message` varchar(500) NOT NULL DEFAULT '',

  /*
   * Aucune clé étrangère vers `users`, volontairement : un journal doit
   * survivre à la suppression du compte qu'il décrit — c'est justement là
   * qu'il sert. Le nom est donc recopié à l'écriture plutôt que joint.
   */
  `user_id` int(11) DEFAULT NULL,
  `utilisateur` varchar(50) DEFAULT NULL,

  `ip` varchar(45) DEFAULT NULL,          -- 45 = taille maximale d'une IPv6
  `chemin` varchar(200) DEFAULT NULL,     -- URI de la requête à l'origine
  `contexte` text DEFAULT NULL,           -- JSON : détails propres à l'action
  `duree_ms` int(11) DEFAULT NULL,        -- pour les opérations longues

  PRIMARY KEY (`id`),

  /*
   * Toutes les lectures de la page Journal sont « les N derniers événements,
   * éventuellement filtrés » : chaque index part donc du critère et se termine
   * par l'horodatage, pour que le tri soit couvert et non refait en mémoire.
   */
  KEY `idx_horodatage` (`horodatage`),
  KEY `idx_niveau` (`niveau`, `horodatage`),
  KEY `idx_canal` (`canal`, `horodatage`),
  KEY `idx_action` (`action`, `horodatage`),
  KEY `idx_utilisateur` (`user_id`, `horodatage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
