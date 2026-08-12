<?php
/**
 * Noyau d'authentification et de contrôle d'accès.
 *
 * Toute page de src/pages/ et toute action de src/actions/ doit commencer par
 * un appel à exigerConnexion(). Les actions qui écrivent en base ajoutent
 * refuserSiDemo() juste après, pour que le mode démonstration reste en
 * lecture seule même si quelqu'un appelle l'endpoint à la main.
 */

// Version de l'application. Inclus ici parce que tout passe par auth.php :
// la constante est ainsi disponible partout sans include supplémentaire.
require_once __DIR__ . '/version.php';

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
 *
 * Cette liste ne contient QUE les comptes proposés publiquement. Le compte
 * d'administration n'y figure pas et ne doit jamais y être ajouté, même
 * temporairement pour un test : ce serait publier son existence.
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
 * Ajoute un numéro de version à l'URL d'un script ou d'une feuille de style.
 *
 * Les pages PHP sont servies en « no-store », mais les fichiers statiques
 * n'ont aucun en-tête Cache-Control : les navigateurs leur appliquent alors un
 * cache heuristique et peuvent servir une version périmée sans revalider. On
 * se retrouve avec un HTML à jour qui appelle du JavaScript qui ne l'est pas.
 *
 * La date de modification du fichier sert de version : l'URL change dès que le
 * fichier change, et le navigateur est obligé de le retélécharger.
 */
function assetVersionne(string $url): string
{
    // Les pages injectées par le routeur écrivent « ../scripts/x.js » ; le
    // navigateur ramène ce « .. » à la racine du site, qui est src/.
    $relatif = ltrim((string) preg_replace('#^(\.\./)+#', '', $url), '/');
    $version = @filemtime(__DIR__ . '/../' . $relatif);

    return $version ? $url . '?v=' . $version : $url;
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
function exigerConnexion(bool $json = false, bool $depuisAdmin = false): void
{
    demarrerSession();

    if (!isset($_SESSION['user']['id'])) {
        http_response_code(401);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Non authentifié']);
        } else {
            echo '<p class="error">Session expirée. <a href="login.php">Se reconnecter</a></p>';
        }
        exit;
    }

    /*
     * Le compte d'administration ne sert qu'à l'administration : il n'a accès
     * ni aux pages d'écoute, ni aux actions qui les servent. La garde est ici
     * plutôt que répétée dans quatorze fichiers — toute page ou action qui
     * appelle exigerConnexion() en hérite, et seul exigerAdmin() la lève.
     */
    if (!$depuisAdmin && estAdmin()) {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'admin'   => true,
                'message' => "Ce compte n'a accès qu'à la section d'administration",
            ]);
        } else {
            echo '<p class="error">Ce compte est réservé à l\'administration. '
               . '<a href="?page=admin" data-page="admin">Retour à la gestion</a></p>';
        }
        exit;
    }
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
 * La session courante est-elle une session d'administration ?
 *
 * Le refus explicite du mode démonstration n'est pas redondant : la garde est
 * ici, dans le code, et pas seulement dans le fait qu'aucun compte admin
 * n'existe en base de démonstration.
 */
function estAdmin(): bool
{
    demarrerSession();
    return !estDemo() && ($_SESSION['user']['role'] ?? 'user') === 'admin';
}

/**
 * Coupe l'exécution si la session n'est pas administratrice.
 * Contient exigerConnexion() : un seul appel suffit en tête de fichier.
 *
 * Le refus est un 404 et non un 403, pour ne pas révéler l'existence de la
 * section d'administration à une session ordinaire — cohérent avec sa
 * connexion discrète. C'est un choix de discrétion, pas de sécurité : il ne
 * dispense d'aucun contrôle, d'où la trace dans les logs.
 */
function exigerAdmin(bool $json = false): void
{
    // Seul appelant à lever la restriction posée par exigerConnexion().
    exigerConnexion($json, true);

    if (estAdmin()) {
        return;
    }

    error_log(sprintf(
        'Accès admin refusé : utilisateur #%s (%s) sur %s',
        $_SESSION['user']['id'] ?? '?',
        $_SESSION['user']['username'] ?? '?',
        $_SERVER['REQUEST_URI'] ?? '?'
    ));

    http_response_code(404);
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Introuvable']);
    } else {
        echo '<p class="error">Page introuvable.</p>';
    }
    exit;
}

/**
 * Identifiants des deux membres du foyer, dans l'ordre d'affichage des cercles
 * de l'en-tête. Le compte d'administration n'en fait pas partie.
 */
const MEMBRES_FOYER = [1, 2];

/**
 * Identifiant de l'autre membre du foyer, pour le second cercle de l'en-tête.
 *
 * Renvoie null pour un compte hors du foyer (administration) : la bascule
 * « contenu commun / seulement le mien » n'a alors aucun sens. Remplace le
 * calcul « 3 - id », qui supposait exactement deux comptes et produisait une
 * classe CSS inexistante dès le troisième.
 */
function idPartenaire(): ?int
{
    demarrerSession();
    $moi = (int) ($_SESSION['user']['id'] ?? 0);

    if (!in_array($moi, MEMBRES_FOYER, true)) {
        return null;
    }

    foreach (MEMBRES_FOYER as $id) {
        if ($id !== $moi) {
            return $id;
        }
    }

    return null;
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
