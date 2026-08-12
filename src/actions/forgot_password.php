<?php
include_once "../includes/auth.php";
include_once "../includes/rateLimit.php";

demarrerSession();

require_once '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Même convention que le login : le client n'envoie qu'une clé de compte.
    $selectedUser = usernameDepuisCle($_POST['selectedUser'] ?? null);

    // Vérifier le token CSRF
    verifierCsrf(true);

    // Sans limite, l'endpoint sert de générateur de spam vers une vraie adresse.
    if (($attente = rlBloque('forgot', 3, 900)) > 0) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Trop de demandes. Réessayez dans ' . ceil($attente / 60) . ' minutes.',
        ]);
        exit;
    }
    rlEchec('forgot');

    if (!$selectedUser) {
        echo json_encode(['success' => true, 'message' => 'Si un compte existe, un email a été envoyé']);
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

        /*
         * L'URL de base vient de la configuration, jamais de l'en-tête Host :
         * celui-ci est contrôlé par le client, et un Host falsifié enverrait
         * dans la vraie boîte mail un lien pointant chez l'attaquant.
         */
        $base = getenv('APP_URL');
        if (!$base) {
            $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
            $base = $scheme . '://' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        }
        $reset_link = rtrim($base, '/') . "/reset_password.php?id=" . urlencode($user['id']) . "&token=" . urlencode($reset_token);

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
            $mail->CharSet    = 'UTF-8';

            // Paramètres du mail
            $mail->setFrom('noreply@mail.pi5.ovh', 'Unison');
            $mail->addAddress($user["email"]);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->AltBody = $message;

            // Envoyer
            $mail->send();

            // Même message que pour un compte inconnu : la réponse ne doit
            // pas permettre de savoir quels comptes existent.
            echo json_encode(['success' => true, 'message' => 'Si un compte existe, un email a été envoyé']);

        } catch (Exception $e) {
            // Le détail SMTP part dans les logs, jamais vers le client :
            // il révèle le fournisseur, les identifiants et la topologie.
            error_log("Erreur PHPMailer: " . $mail->ErrorInfo);
            echo json_encode([
                'success' => false,
                'message' => "L'envoi a échoué. Réessayez plus tard."
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