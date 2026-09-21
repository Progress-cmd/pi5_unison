<?php
/**
 * Alimente les tableaux Titres et Artistes de la page Contenu.
 *
 * En GET, comme la consultation du journal et pour la même raison : c'est une
 * lecture, rejouable et rafraîchissable sans conséquence. Un jeton CSRF
 * protège d'une écriture déclenchée à l'insu de l'utilisateur ; il n'aurait
 * pas de sens ici. La garde qui compte, exigerAdmin(), est bien présente.
 *
 * Entrée GET : type (titres|artistes), recherche, page
 * Sortie JSON : { success, lignes, total, page, pages, par_page }
 */
include_once "../../includes/auth.php";
include_once "../../includes/config.php";
include_once "../../includes/contenuRapport.php";

header('Content-Type: application/json');
exigerAdmin(true);

$type      = (string) filter_input(INPUT_GET, 'type', FILTER_DEFAULT);
$recherche = (string) filter_input(INPUT_GET, 'recherche', FILTER_DEFAULT);
$page      = (int) filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);

$pdo = Config::getConnection();

// Le type est comparé à une liste fermée : il ne sert jamais à construire du
// SQL, et une valeur inattendue retombe simplement sur les titres.
$resultat = $type === 'artistes'
    ? contenuArtistes($pdo, $recherche, max(1, $page))
    : contenuTitres($pdo, $recherche, max(1, $page));

echo json_encode([
    'success'  => true,
    'lignes'   => $resultat['lignes'],
    'total'    => $resultat['total'],
    'page'     => $resultat['page'],
    'pages'    => $resultat['pages'],
    'par_page' => CONTENU_PAR_PAGE,
]);
