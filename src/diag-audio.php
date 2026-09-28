<?php
/**
 * Page de diagnostic audio — temporaire.
 *
 * Elle répond à une seule question : le problème vient-il du serveur et du
 * flux, ou de la page qui pilote la lecture ? Les deux lecteurs ci-dessous
 * jouent le MÊME fichier, l'un par une balise <audio> native posée dans la
 * page, l'autre par l'élément détaché que crée player.js. Le résultat désigne
 * le coupable sans discussion.
 *
 * À supprimer une fois le diagnostic fait.
 */
include_once "includes/auth.php";
exigerConnexion(false);
include_once "includes/config.php";

$pdo = Config::getConnection();
$t = $pdo->query("SELECT id, title, file FROM tracks WHERE file IS NOT NULL ORDER BY id LIMIT 1")
         ->fetch(PDO::FETCH_ASSOC);
$url = $t ? 'actions/stream.php?file=' . rawurlencode($t['file']) : '';
?>
<!doctype html>
<html lang="fr"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnostic audio</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 0; padding: 18px; line-height: 1.5;
         background: #f8f7f5; color: #1c1916; }
  h1 { font-size: 19px; } h2 { font-size: 16px; margin-top: 26px; }
  audio { width: 100%; margin-top: 8px; }
  pre { background: #fff; border: 1px solid #dfcbc0; border-radius: 8px;
        padding: 10px; font-size: 12px; overflow-x: auto; }
  button { padding: 10px 16px; font-size: 15px; border-radius: 8px;
           border: 1px solid #dfcbc0; background: #fff; }
</style></head>
<body>
<h1>Diagnostic audio</h1>
<p>Titre utilisé : <strong><?= htmlspecialchars($t['title'] ?? 'aucun', ENT_QUOTES) ?></strong></p>

<h2>1. Lecteur natif (balise dans la page)</h2>
<p>Appuyez sur lecture. Si le son sort ici, le serveur et le flux sont bons.</p>
<audio controls preload="metadata" src="<?= htmlspecialchars($url, ENT_QUOTES) ?>"></audio>

<h2>2. Élément détaché, comme dans l'application</h2>
<p>C'est exactement ce que fait player.js : <code>new Audio()</code>, hors du DOM.</p>
<button type="button" id="jouer">Lire avec un élément détaché</button>
<pre id="etat">—</pre>

<h2>3. Ce que voit le navigateur</h2>
<pre id="infos">—</pre>

<script>
const url = <?= json_encode($url) ?>;
const etat = document.getElementById('etat');
const detache = new Audio(url);

document.getElementById('jouer').addEventListener('click', async () => {
    try {
        await detache.play();
        etat.textContent = 'play() accepté — écoutez : entendez-vous quelque chose ?';
    } catch (e) {
        etat.textContent = 'play() REFUSÉ : ' + e.name + ' — ' + e.message;
    }
});

detache.addEventListener('error', () => {
    etat.textContent = 'erreur de chargement, code ' + (detache.error && detache.error.code);
});

function rapport() {
    const l = [];
    l.push('URL du flux        : ' + url);
    l.push('contexte sécurisé  : ' + window.isSecureContext);
    l.push('service worker     : ' + (navigator.serviceWorker && navigator.serviceWorker.controller ? 'actif' : 'aucun'));
    l.push('');
    l.push('-- élément détaché --');
    l.push('volume=' + detache.volume + '  muted=' + detache.muted);
    l.push('paused=' + detache.paused + '  currentTime=' + detache.currentTime.toFixed(1));
    l.push('readyState=' + detache.readyState + '  networkState=' + detache.networkState);
    l.push('erreur=' + (detache.error ? detache.error.code : 'aucune'));
    const natif = document.querySelector('audio');
    l.push('');
    l.push('-- lecteur natif --');
    l.push('volume=' + natif.volume + '  muted=' + natif.muted);
    l.push('readyState=' + natif.readyState + '  erreur=' + (natif.error ? natif.error.code : 'aucune'));
    l.push('');
    l.push('-- réglage mémorisé sur cet appareil --');
    try { l.push('volume en mémoire : ' + localStorage.getItem('unison.volume')); }
    catch (e) { l.push('localStorage illisible'); }
    document.getElementById('infos').textContent = l.join('\n');
}
rapport();
setInterval(rapport, 1000);
</script>
</body></html>
