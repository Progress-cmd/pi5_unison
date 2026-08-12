<?php
/**
 * Outils partagés par la section d'administration.
 *
 * Regroupe ici tout ce qui est utilisé à la fois par les pages (affichage) et
 * par les actions (écriture), pour que les deux voient exactement le même état
 * — en particulier le rapprochement entre les fichiers du disque et la base.
 *
 * Aucune garde n'est posée dans ce fichier : c'est aux appelants de commencer
 * par exigerAdmin().
 */

require_once __DIR__ . '/config.php';

/** Chiffres globaux du tableau de bord. */
function statistiquesGlobales(PDO $pdo): array
{
    $compte = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();

    return [
        'titres'     => $compte("SELECT COUNT(*) FROM tracks"),
        'artistes'   => $compte("SELECT COUNT(*) FROM artists"),
        'genres'     => $compte("SELECT COUNT(*) FROM genres"),
        'tags'       => $compte("SELECT COUNT(*) FROM tags"),
        // Les playlists système (2 par compte) ne sont pas du contenu créé.
        'playlists'  => $compte("SELECT COUNT(*) FROM playlists
                                 WHERE name NOT IN ('Wait Tracks', 'Favorite Tracks')"),
        'notes'      => $compte("SELECT COUNT(*) FROM notes"),
        'comptes'    => $compte("SELECT COUNT(*) FROM users"),
        // L'historique fait foi : c'est le journal des écoutes, et c'est lui
        // qui alimente les graphiques. nb_listen est un compteur cumulé qui
        // peut diverger — afficher les deux sous le même nom induirait en erreur.
        'ecoutes'    => $compte("SELECT COUNT(*) FROM historical"),
        'duree'      => $compte("SELECT COALESCE(SUM(duration), 0) FROM tracks"),
    ];
}

/**
 * Rapproche le contenu du dossier audio et la table tracks.
 *
 * Deux anomalies possibles, symétriques :
 *  - orphelins : un fichier sur le disque que plus aucun titre ne référence
 *    (une importation interrompue, un titre supprimé à la main…) ;
 *  - manquants : un titre en base dont le fichier a disparu — plus grave,
 *    car il est visible dans l'interface mais ne se lit pas.
 */
function analyserStockage(PDO $pdo): array
{
    $dossier = Config::cheminMusiques();

    $enBase = [];
    foreach ($pdo->query("SELECT id, title, file FROM tracks")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $enBase[$t['file']] = $t;
    }

    $orphelins = [];
    $octetsOrphelins = 0;
    $octetsTotal = 0;

    foreach (@scandir($dossier) ?: [] as $entree) {
        if ($entree === '.' || $entree === '..') {
            continue;
        }

        $chemin = $dossier . $entree;
        if (!is_file($chemin)) {
            continue; // ignore le sous-dossier demo/
        }

        $taille = (int) @filesize($chemin);
        $octetsTotal += $taille;

        if (!isset($enBase[$entree])) {
            $orphelins[] = ['fichier' => $entree, 'octets' => $taille];
            $octetsOrphelins += $taille;
        }
    }

    $manquants = [];
    foreach ($enBase as $fichier => $titre) {
        if ($fichier === '' || !is_file($dossier . $fichier)) {
            $manquants[] = $titre;
        }
    }

    // Les plus gros d'abord : c'est l'ordre utile pour faire de la place.
    usort($orphelins, fn($a, $b) => $b['octets'] <=> $a['octets']);

    return [
        'dossier'          => $dossier,
        'orphelins'        => $orphelins,
        'manquants'        => $manquants,
        'octets_total'     => $octetsTotal,
        'octets_orphelins' => $octetsOrphelins,
    ];
}

/** Taille lisible : « 1,6 Go ». */
function formaterOctets(int $octets): string
{
    $unites = ['o', 'ko', 'Mo', 'Go', 'To'];
    $i = 0;
    $v = (float) $octets;

    while ($v >= 1024 && $i < count($unites) - 1) {
        $v /= 1024;
        $i++;
    }

    return str_replace('.', ',', (string) round($v, $i > 1 ? 1 : 0)) . ' ' . $unites[$i];
}

/** Durée lisible : « 3 h 42 » ou « 12 min ». */
function formaterDuree(int $secondes): string
{
    if ($secondes >= 3600) {
        return intdiv($secondes, 3600) . ' h ' . str_pad((string) intdiv($secondes % 3600, 60), 2, '0', STR_PAD_LEFT);
    }
    return intdiv($secondes, 60) . ' min';
}

/**
 * Client MeiliSearch, ou null si indisponible.
 * L'indisponibilité de la recherche ne doit jamais faire échouer une opération
 * de gestion : l'index se reconstruit, la base non.
 */
function clientMeili(): ?object
{
    if (!class_exists('Meilisearch\\Client')) {
        return null;
    }

    try {
        return new Meilisearch\Client('http://ms:7700', getenv('MS_PASS') ?: null);
    } catch (\Exception $e) {
        error_log('Meilisearch indisponible : ' . $e->getMessage());
        return null;
    }
}

/** Comptes, avec ce qu'ils ont produit — nécessaire avant toute suppression. */
function listerComptes(PDO $pdo): array
{
    $req = $pdo->query("
        SELECT users.id, users.username, users.email, users.role, users.`time-listened` AS temps,
               (SELECT COUNT(*) FROM tracks WHERE tracks.`added-by_id` = users.id) AS nb_titres,
               (SELECT COUNT(*) FROM playlists
                 WHERE playlists.`created-by_id` = users.id
                   AND playlists.name NOT IN ('Wait Tracks','Favorite Tracks')) AS nb_playlists,
               (SELECT COUNT(*) FROM historical WHERE historical.`listened-by_id` = users.id) AS nb_ecoutes
        FROM users
        ORDER BY users.id
    ");

    return $req->fetchAll(PDO::FETCH_ASSOC);
}
