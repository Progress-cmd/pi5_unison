<?php
/**
 * Conversion des fichiers audio vers le format unique de l'application.
 *
 * Les imports produisaient du WAV — onze fois plus lourd que la source
 * YouTube dont il était décompressé, pour la même information. Le nouveau
 * format est le m4a (AAC), repris tel quel au téléchargement. Restent les
 * fichiers déjà présents, que ce module aligne.
 *
 * Ré-encodage local plutôt que retéléchargement : ce dernier rendrait la
 * qualité d'origine, mais une vidéo retirée depuis laisserait son WAV en
 * place — donc pas d'uniformité, qui est justement le but. Le ré-encodage,
 * lui, aboutit toujours.
 *
 * 192 kbit/s pour un WAV issu d'une source à ~128 kbit/s : le débit
 * supérieur laisse de la marge à cette seconde génération, qui reste alors
 * transparente à l'oreille.
 */

const CONVERSION_FORMAT     = 'm4a';
const CONVERSION_DEBIT      = '192k';
const CONVERSION_TOLERANCE  = 2.0;   // écart de durée toléré, en secondes

/**
 * Titres dont le fichier n'est pas encore au format cible.
 *
 * @return array{titres: array, octets: int, manquants: int}
 */
function conversionLister(PDO $pdo): array
{
    $dossier = Config::cheminMusiques();

    $req = $pdo->query("SELECT id, title, file FROM tracks WHERE file != '' ORDER BY id");

    $titres = [];
    $octets = 0;
    $manquants = 0;

    foreach ($req->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $nom = basename((string) $t['file']);

        if (strtolower((string) pathinfo($nom, PATHINFO_EXTENSION)) === CONVERSION_FORMAT) {
            continue;
        }

        // Un fichier absent n'est pas convertible : il relève du nettoyage de
        // stockage, pas d'ici. On le compte pour que le total reste honnête.
        if (!is_file($dossier . $nom)) {
            $manquants++;
            continue;
        }

        $taille = (int) filesize($dossier . $nom);
        $octets += $taille;

        $titres[] = [
            'id'      => (int) $t['id'],
            'titre'   => (string) $t['title'],
            'fichier' => $nom,
            'octets'  => $taille,
        ];
    }

    return ['titres' => $titres, 'octets' => $octets, 'manquants' => $manquants];
}

/** Durée d'un fichier audio en secondes, ou null si illisible. */
function conversionDuree(string $chemin): ?float
{
    $sortie = @shell_exec(
        'ffprobe -v error -show_entries format=duration -of default=nw=1:nk=1 '
        . escapeshellarg($chemin) . ' 2>/dev/null'
    );

    $duree = is_string($sortie) ? (float) trim($sortie) : 0.0;

    return $duree > 0 ? $duree : null;
}

/**
 * Convertit le fichier d'un titre, puis remplace l'ancien.
 *
 * L'ordre est celui qui permet de tout interrompre sans rien perdre :
 * on écrit un fichier temporaire, on le vérifie, on le renomme, on met la
 * base à jour, et seulement alors on supprime l'original. Une coupure à
 * n'importe quel moment laisse un titre lisible.
 *
 * @return array{success: bool, message: string, avant?: int, apres?: int}
 */
function conversionTitre(PDO $pdo, int $trackId): array
{
    $req = $pdo->prepare("SELECT id, title, file, duration FROM tracks WHERE id = :id");
    $req->execute([':id' => $trackId]);
    $titre = $req->fetch(PDO::FETCH_ASSOC);

    if (!$titre) {
        return ['success' => false, 'message' => 'Titre introuvable'];
    }

    $dossier = Config::cheminMusiques();
    $nom     = basename((string) $titre['file']);
    $source  = $dossier . $nom;

    if (strtolower((string) pathinfo($nom, PATHINFO_EXTENSION)) === CONVERSION_FORMAT) {
        return ['success' => true, 'message' => 'Déjà au format'];
    }

    if ($nom === '' || !is_file($source)) {
        return ['success' => false, 'message' => 'Fichier absent du disque'];
    }

    $base        = pathinfo($nom, PATHINFO_FILENAME);
    $nomCible    = $base . '.' . CONVERSION_FORMAT;
    $cible       = $dossier . $nomCible;
    $temporaire  = $dossier . $base . '.conversion.' . CONVERSION_FORMAT;

    $octetsAvant = (int) filesize($source);

    /*
     * -vn : les WAV n'ont pas d'image, mais certains conteneurs importés en
     * portent une, et l'encodeur AAC échoue si on la lui passe.
     * -movflags +faststart : l'index est placé en tête du fichier, sans quoi
     * le lecteur doit tout télécharger avant de connaître la durée.
     */
    $commande = 'ffmpeg -v error -y -i ' . escapeshellarg($source)
              . ' -vn -c:a aac -b:a ' . CONVERSION_DEBIT
              . ' -movflags +faststart ' . escapeshellarg($temporaire) . ' 2>&1';

    @exec($commande, $sortie, $code);

    if ($code !== 0 || !is_file($temporaire) || filesize($temporaire) === 0) {
        @unlink($temporaire);
        journalErreur('stockage', 'conversion_echouee',
            'Conversion impossible : ' . $titre['title'],
            ['track_id' => $trackId, 'fichier' => $nom,
             'code' => $code, 'sortie' => implode(' | ', array_slice($sortie, -3))]);

        return ['success' => false, 'message' => 'Conversion échouée'];
    }

    /*
     * Contrôle de cohérence : un ffmpeg qui rend un fichier tronqué sort
     * malgré tout en code 0. Comparer les durées est ce qui attrape ce cas,
     * et c'est la seule vérification qui vaille avant d'effacer l'original.
     */
    $dureeSource = conversionDuree($source);
    $dureeCible  = conversionDuree($temporaire);

    if ($dureeSource === null || $dureeCible === null
        || abs($dureeSource - $dureeCible) > CONVERSION_TOLERANCE) {
        @unlink($temporaire);
        journalErreur('stockage', 'conversion_incoherente',
            'Durée incohérente après conversion : ' . $titre['title'],
            ['track_id' => $trackId, 'fichier' => $nom,
             'duree_source' => $dureeSource, 'duree_cible' => $dureeCible]);

        return ['success' => false, 'message' => 'Durée incohérente, original conservé'];
    }

    if (!@rename($temporaire, $cible)) {
        @unlink($temporaire);
        return ['success' => false, 'message' => 'Renommage impossible'];
    }

    $req = $pdo->prepare("UPDATE tracks SET file = :file WHERE id = :id");
    $req->execute([':file' => $nomCible, ':id' => $trackId]);

    // L'original n'est retiré qu'une fois la base à jour : dans l'autre ordre,
    // une coupure laisserait un titre pointant sur un fichier disparu.
    $octetsApres = (int) filesize($cible);
    if ($cible !== $source) {
        @unlink($source);
    }

    journalInfo('stockage', 'titre_converti',
        'Converti en ' . CONVERSION_FORMAT . ' : ' . $titre['title'],
        ['track_id' => $trackId, 'avant' => $nom, 'apres' => $nomCible,
         'octets_avant' => $octetsAvant, 'octets_apres' => $octetsApres]);

    return [
        'success' => true,
        'message' => 'Converti',
        'avant'   => $octetsAvant,
        'apres'   => $octetsApres,
    ];
}
