<?php
/**
 * Noyau d'authentification et de contrôle d'accès.
 *
 * Toute page de src/pages/ et toute action de src/actions/ doit commencer par
 * un appel à exigerConnexion(). Les actions qui écrivent en base ajoutent
 * refuserSiDemo() juste après, pour que le mode démonstration reste en
 * lecture seule même si quelqu'un appelle l'endpoint à la main.
 */

/**
 * Compte utilisé par les sessions de démonstration.
 *
 * Il n'existe que dans la base de démonstration (voir demo_data/) : cet
 * identifiant ne désigne aucun compte réel, puisque Config aiguille toute
 * session de démonstration vers une base entièrement distincte.
 */
const DEMO_USER_ID = 1;
const DEMO_USERNAME = 'Alex';

/**
 * Comptes proposés sur la page de connexion.
 *
 * La page publique n'affiche et n'envoie que la clé (« tortue », « papillon »)
 * et le libellé : le nom d'utilisateur réel ne quitte jamais le serveur.
 * Un attaquant doit donc deviner l'identifiant en plus du mot de passe, et la
 * page d'accueil ne divulgue aucune donnée personnelle.
 */
function comptesConnexion(): array
{
    return [
        'tortue'   => ['libelle' => 'Tortue',   'couleur' => '#C8593A', 'username' => 'Francis'],
        'papillon' => ['libelle' => 'Papillon', 'couleur' => '#4A7C99', 'username' => 'Cassandre'],
    ];
}

/** Nom d'utilisateur correspondant à une clé de compte, ou null si inconnue. */
function usernameDepuisCle(?string $cle): ?string
{
    $comptes = comptesConnexion();
    return isset($comptes[$cle]) ? $comptes[$cle]['username'] : null;
}

/**
 * Démarre la session si elle ne l'est pas déjà.
 * Évite les "session already started" quand plusieurs includes s'enchaînent.
 */
function demarrerSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * Coupe l'exécution si l'utilisateur n'est pas connecté.
 *
 * @param bool $json true pour une action appelée en fetch() (réponse JSON),
 *                   false pour une page (réponse HTML).
 */
function exigerConnexion(bool $json = false): void
{
    demarrerSession();

    if (isset($_SESSION['user']['id'])) {
        return;
    }

    http_response_code(401);
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    } else {
        echo '<p class="error">Session expirée. <a href="login.php">Se reconnecter</a></p>';
    }
    exit;
}

/** La session courante est-elle une session de démonstration ? */
function estDemo(): bool
{
    demarrerSession();
    return !empty($_SESSION['user']['is_demo']);
}

/**
 * Coupe l'exécution si la session est une démonstration.
 * À placer en tête de toute action qui modifie des données.
 */
function refuserSiDemo(bool $json = true): void
{
    if (!estDemo()) {
        return;
    }

    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'demo'    => true,
            'message' => 'Mode démonstration : les modifications sont désactivées.',
        ]);
    } else {
        echo '<p class="error">Mode démonstration : les modifications sont désactivées.</p>';
    }
    exit;
}

/**
 * Renvoie le jeton CSRF de la session, en le créant au besoin.
 * Un seul jeton par session, réutilisé par tous les formulaires : le
 * régénérer à chaque page invaliderait les formulaires déjà ouverts.
 */
function jetonCsrf(): string
{
    demarrerSession();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/**
 * Vérifie le jeton CSRF envoyé en POST et coupe l'exécution s'il est mauvais.
 * Comparaison en temps constant pour ne rien laisser fuir.
 */
function verifierCsrf(bool $json = true): void
{
    demarrerSession();

    $recu = $_POST['token'] ?? $_POST['csrf'] ?? '';

    if (!empty($_SESSION['csrf']) && is_string($recu) && hash_equals($_SESSION['csrf'], $recu)) {
        return;
    }

    http_response_code(403);
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Jeton invalide']);
    } else {
        echo 'Jeton invalide';
    }
    exit;
}
