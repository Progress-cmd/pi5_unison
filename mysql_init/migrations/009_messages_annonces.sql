-- Migration à appliquer sur une base existante.
--
-- mysql_init/ n'est rejoué par Docker que sur une base vierge ; ce fichier est
-- appliqué aux installations en service par docker/appliquer_migrations.sh.
--
-- Deux ajouts distincts, qui partagent une même idée : ne rien montrer deux fois.
--
--   messages       le mini-chat du foyer, avec titre joint facultatif
--   annonces       nouveautés et messages de l'administration
--   annonces_vues  qui a déjà vu quoi — une annonce ne se réaffiche jamais
--
-- Plus une préférence de notification par compte (toast ou notification système).

CREATE TABLE IF NOT EXISTS `messages` (
    `id`              int(11)       NOT NULL AUTO_INCREMENT,
    `expediteur_id`   int(11)       NOT NULL,
    -- Explicite malgré les deux seuls comptes : « non lus pour moi » devient
    -- une condition simple, et un troisième compte ne casserait rien.
    `destinataire_id` int(11)       NOT NULL,
    -- Un message peut n'être qu'un titre partagé, sans un mot.
    `contenu`         varchar(2000)          DEFAULT NULL,
    `track_id`        int(11)                DEFAULT NULL,
    `cree_a`          timestamp     NOT NULL DEFAULT current_timestamp(),
    `lu_a`            timestamp     NULL     DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- Sert la requête des non-lus, la seule appelée toutes les 15 secondes.
    KEY `messages_non_lus` (`destinataire_id`, `lu_a`),
    KEY `messages_fil` (`cree_a`),
    KEY `messages_track` (`track_id`),
    KEY `messages_expediteur` (`expediteur_id`),
    CONSTRAINT `messages_expediteur_fk` FOREIGN KEY (`expediteur_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `messages_destinataire_fk` FOREIGN KEY (`destinataire_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    -- Un titre supprimé ne doit pas emporter la conversation : le message
    -- reste, il perd seulement sa pièce jointe.
    CONSTRAINT `messages_track_fk` FOREIGN KEY (`track_id`)
        REFERENCES `tracks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `annonces` (
    `id`      int(11)      NOT NULL AUTO_INCREMENT,
    `titre`   varchar(120) NOT NULL,
    `contenu` text         NOT NULL,
    -- 'fonctionnalite' ou 'message' : change seulement l'icône et le ton.
    `type`    varchar(20)  NOT NULL DEFAULT 'message',
    `cree_a`  timestamp    NOT NULL DEFAULT current_timestamp(),
    -- Retirée sans être supprimée : les vues déjà enregistrées restent, et
    -- la réactiver ne la réaffiche donc pas à qui l'avait déjà lue.
    `actif`   tinyint(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `annonces_actives` (`actif`, `cree_a`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `annonces_vues` (
    `annonce_id` int(11)   NOT NULL,
    `user_id`    int(11)   NOT NULL,
    `vu_a`       timestamp NOT NULL DEFAULT current_timestamp(),
    -- La clé composée est la garantie du « une seule fois » : une seconde
    -- vue ne peut pas s'insérer, quel que soit le nombre d'onglets ouverts.
    PRIMARY KEY (`annonce_id`, `user_id`),
    KEY `annonces_vues_user` (`user_id`),
    CONSTRAINT `annonces_vues_annonce_fk` FOREIGN KEY (`annonce_id`)
        REFERENCES `annonces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `annonces_vues_user_fk` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 'toast' (par défaut) ou 'systeme'. La notification système exige un contexte
-- sécurisé : elle reste inopérante en HTTP sur une IP locale, comme les
-- notifications média.
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `notif_mode` varchar(10) NOT NULL DEFAULT 'toast'
        AFTER `theme`;
