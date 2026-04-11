<?php
session_start();

require_once '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedUser = $_POST['selectedUser'] ?? null;
    $token = $_POST['token'] ?? null;

    // Vérifier le token CSRF
    if (!isset($_SESSION['token']) || $token !== $_SESSION['token']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token invalide']);
        exit;
    }

    if (!$selectedUser) {
        echo json_encode(['success' => false, 'message' => 'Aucun utilisateur sélectionné']);
        exit;
    }

    include_once "../includes/config.php";

    try {
        $pdo = Config::getConnection();

        // Vérifier que l'utilisateur existe
        $req = $pdo->prepare("SELECT id, email FROM users WHERE username = :username");
        $req->bindValue(':username', $selectedUser);
        $req->execute();
        $user = $req->fetch();

        if (!$user) {
            // Ne pas révéler si l'utilisateur existe (sécurité)
            echo json_encode(['success' => true, 'message' => 'Si un compte existe, un email a été envoyé']);
            exit;
        }

        // Générer un token de réinitialisation sécurisé
        $reset_token = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $reset_token);
        date_default_timezone_set('Europe/Paris');
        $expires_at = date('Y-m-d H:i:s', time() + 3600); // Expire dans 1h

        // Stocker le token hashé en base de données
        $updateReq = $pdo->prepare(
            "UPDATE users SET reset_token = :token, reset_token_expires = :expires WHERE id = :id"
        );
        $updateReq->execute([
            ':token' => $token_hash,
            ':expires' => $expires_at,
            ':id' => $user['id']
        ]);

        // Construire le lien dynamiquement
        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $reset_link = "$scheme://$host/reset_password.php?id=" . urlencode($user['id']) . "&token=" . urlencode($reset_token);

        // Préparer l'email
        $subject = "Réinitialiser votre mot de passe";
        $message = "Bonjour,\n\n";
        $message .= "Vous avez demandé une réinitialisation de mot de passe.\n";
        $message .= "Cliquez sur ce lien pour continuer :\n\n";
        $message .= $reset_link . "\n\n";
        $message .= "Ce lien expire dans 1 heure.\n";
        $message .= "Si vous n'avez pas demandé cela, ignorez ce message.";

        $mail = new PHPMailer(true);

        try {
            // Configuration SMTP
            $mail->isSMTP();
            $mail->Host = getenv('MAIL_HOST');
            $mail->Port = getenv('MAIL_PORT');
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Username = getenv('MAIL_USER');
            $mail->Password = getenv('MAIL_PASS');

            // Paramètres du mail
            $mail->setFrom('noreply@pi5.ovh', 'Unison');
            $mail->addAddress($user["email"]);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->AltBody = $message;

            // Envoyer
            $mail->send();

            echo json_encode([
                'success' => true,
                'message' => 'Email envoyé avec succès'
            ]);

        } catch (Exception $e) {
            $errorMsg = $mail->ErrorInfo;
            error_log("Erreur PHPMailer: " . $errorMsg);
            echo json_encode([
                'success' => false,
            ]);
        }

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
        error_log("Erreur DB: " . $e->getMessage());
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
}
?>