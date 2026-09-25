<?php
/**
 * Création, activation et suppression d'une annonce, depuis l'administration.
 *
 * Une annonce s'affiche une seule fois par compte : c'est la table
 * annonces_vues qui le garantit, jamais cette action.
 *
 * Entrée POST : operation (creer|basculer|supprimer) + champs selon le cas
 * Sortie JSON : { success, message, annonce? }
 */
include_once "../../includes/auth.php";
exigerAdmin(true);
verifierCsrf(true);

header('Content-Type: application/json');

include_once "../../includes/config.php";

$operation = (string) filter_input(INPUT_POST, 'operation', FILTER_DEFAULT);

try {
    $pdo = Config::getConnection();

    if ($operation === 'creer') {
        $titre   = trim((string) filter_input(INPUT_POST, 'titre', FILTER_DEFAULT));
        $contenu = trim((string) filter_input(INPUT_POST, 'contenu', FILTER_DEFAULT));
        $type    = (string) filter_input(INPUT_POST, 'type', FILTER_DEFAULT);

        if ($titre === '' || $contenu === '') {
            echecJson('annonce', null, 'Titre et message sont requis', 400, 'admin');
            exit;
        }
        if (mb_strlen($titre) > 120) {
            echecJson('annonce', null, 'Titre trop long (120 caractères maximum)', 400, 'admin');
            exit;
        }
        if (!in_array($type, ['fonctionnalite', 'message'], true)) {
            $type = 'message';
        }

        $req = $pdo->prepare(
            "INSERT INTO annonces (titre, contenu, type) VALUES (:t, :c, :ty)"
        );
        $req->execute([':t' => $titre, ':c' => $contenu, ':ty' => $type]);

        // lastInsertId() avant le journal : il écrit sur la même connexion.
        $id = (int) $pdo->lastInsertId();

        journalInfo('admin', 'annonce_creee', 'Annonce publiée : « ' . $titre . ' »',
                    ['annonce_id' => $id, 'type' => $type]);

        echo json_encode(['success' => true, 'message' => 'Annonce publiée',
                          'annonce' => ['id' => $id]]);
        exit;
    }

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        echecJson('annonce', null, 'Annonce inconnue', 400, 'admin');
        exit;
    }

    if ($operation === 'basculer') {
        /*
         * Désactiver ne supprime pas les vues déjà enregistrées : réactiver
         * une annonce ne la renvoie donc pas à qui l'avait déjà lue. C'est
         * voulu — le contraire transformerait la bascule en rappel forcé.
         */
        $req = $pdo->prepare("UPDATE annonces SET actif = 1 - actif WHERE id = :id");
        $req->execute([':id' => $id]);

        journalInfo('admin', 'annonce_basculee', 'Annonce activée ou retirée',
                    ['annonce_id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Annonce mise à jour']);
        exit;
    }

    if ($operation === 'supprimer') {
        // Les vues partent avec elle (ON DELETE CASCADE) : une annonce
        // supprimée puis recréée est une nouvelle annonce, et se réaffiche.
        $req = $pdo->prepare("DELETE FROM annonces WHERE id = :id");
        $req->execute([':id' => $id]);

        journalAttention('admin', 'annonce_supprimee', 'Annonce supprimée',
                         ['annonce_id' => $id]);

        echo json_encode(['success' => true, 'message' => 'Annonce supprimée']);
        exit;
    }

    echecJson('annonce', null, 'Opération inconnue', 400, 'admin');
} catch (Throwable $e) {
    echecJson('annonce', $e, 'Opération impossible', 500, 'admin');
}
