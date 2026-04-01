<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="styles/accueil.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500;600&display=swap">
    <title>Unison - Accueil</title>
</head>
<body>
<header>
    <div class="accueil-headline">Bonsoir,<br><em>Francis</em></div>
    <p class="accueil-sub">Que voulez-vous écouter ce soir ?</p>
    <div class="present-moi" style="border: <?php if ($_SESSION['user']['username']=='Francis') { echo "#C8593A"; } else { echo "#4A7C99"; }?> 2px solid;">OO</div>
    <div class="present-toi" style="border: <?php if ($_SESSION['user']['username']=='Cassandre') { echo "#C8593A"; } else { echo "#4A7C99"; }?> 2px solid;">OO</div>
</header>