<?php
include_once "../includes/auth.php";
exigerConnexion(true);
refuserSiDemo(true);
if (
    !isset($_POST['token'], $_SESSION['token']) ||
    $_POST['token'] !== $_SESSION['token']
) {
    die('Token invalide');
}

$name = trim(filter_input(INPUT_POST, 'name', FILTER_DEFAULT));
if (empty($name) || strlen($name) > 100) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Nom invalide']);
    exit;
}

include_once "../includes/config.php";
$pdo = Config::getConnection();

$req = $pdo->prepare("INSERT INTO playlists (name, `created-by_id`) VALUES (:name, :user)");
$req->bindParam(':name', $name);
$req->bindParam(':user', $_SESSION['user']['id']);
$req->execute();

header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Playlist <em>'.$name.'</em> créée !']);
exit;