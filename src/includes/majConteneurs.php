<?php
/**
 * Boîte aux lettres morte pour les mises à jour de conteneurs.
 *
 * L'application n'a AUCUN accès à Docker : elle dépose un fichier de demande
 * dans un dossier partagé avec l'hôte, et lit un fichier d'état que le script
 * de l'hôte y écrit. Le conteneur web ne peut donc rien faire d'autre que
 * créer ce signal — même compromis, il ne dispose d'aucun moyen d'exécuter
 * une commande arbitraire sur le Raspberry Pi.
 *
 * C'est aussi pourquoi la demande ne transporte AUCUN paramètre exploitable :
 * seulement une action choisie dans une liste blanche. Ajouter un jour un
 * champ « branche », « tag » ou « commande » transformerait ce mécanisme en
 * exécution de code à distance en bonne et due forme.
 */

const MAJ_DOSSIER = '/var/www/maj';
const MAJ_DEMANDE = MAJ_DOSSIER . '/demande.json';
const MAJ_ETAT    = MAJ_DOSSIER . '/etat.json';

/** Délai minimal entre deux demandes, en secondes. */
const MAJ_DELAI = 900;

/**
 * Actions que l'hôte sait exécuter. Le libellé est informatif ; seule la clé
 * est transmise, et le script de l'hôte ne doit reconnaître que ces valeurs.
 */
function majActions(): array
{
    return [
        'recharger' => [
            'libelle' => 'Recharger l\'application',
            'detail'  => 'git pull puis rechargement gracieux d\'Apache. Sans coupure de service.',
        ],
        'reconstruire' => [
            'libelle' => 'Reconstruire les conteneurs',
            'detail'  => 'Arrêt, reconstruction des images et redémarrage. '
                       . 'Coupure de plusieurs minutes — nécessaire seulement quand le '
                       . 'Dockerfile, le compose ou les dépendances ont changé.',
        ],
    ];
}

/** Le mécanisme est-il installé ? (volume monté et accessible en écriture) */
function majDisponible(): bool
{
    return is_dir(MAJ_DOSSIER) && is_writable(MAJ_DOSSIER);
}

/**
 * État publié par le script de l'hôte.
 * Toujours un tableau exploitable, même si le fichier est absent ou illisible.
 */
function majEtat(): array
{
    $defaut = [
        'statut'  => 'inactif',
        'message' => 'Aucune mise à jour enregistrée',
        'depuis'  => null,
        'version' => null,
    ];

    if (!is_file(MAJ_ETAT)) {
        return $defaut;
    }

    $brut = @file_get_contents(MAJ_ETAT);
    $etat = json_decode((string) $brut, true);

    if (!is_array($etat)) {
        return ['statut' => 'echec', 'message' => "Fichier d'état illisible",
                'depuis' => null, 'version' => null];
    }

    // Le fichier vient de l'hôte, mais on ne lui fait pas confiance pour
    // l'affichage : statut contraint, textes tronqués.
    $statut = (string) ($etat['statut'] ?? 'inactif');
    if (!in_array($statut, ['inactif', 'en_cours', 'succes', 'echec'], true)) {
        $statut = 'inactif';
    }

    return [
        'statut'  => $statut,
        'message' => mb_substr((string) ($etat['message'] ?? ''), 0, 300),
        'depuis'  => mb_substr((string) ($etat['depuis'] ?? ''), 0, 40) ?: null,
        'version' => mb_substr((string) ($etat['version'] ?? ''), 0, 40) ?: null,
    ];
}

/** Demande en attente, ou null. */
function majDemandeEnCours(): ?array
{
    if (!is_file(MAJ_DEMANDE)) {
        return null;
    }

    $demande = json_decode((string) @file_get_contents(MAJ_DEMANDE), true);
    return is_array($demande) ? $demande : null;
}

/**
 * Dépose une demande.
 *
 * Écriture atomique : le fichier est écrit à côté puis renommé, sinon le cron
 * — qui passe toutes les minutes — peut le lire à moitié écrit.
 *
 * @return array{0:bool,1:string} [succès, message]
 */
function majDeposerDemande(string $action, string $par): array
{
    if (!isset(majActions()[$action])) {
        return [false, 'Action inconnue'];
    }

    if (!majDisponible()) {
        return [false, "Le dossier de mise à jour n'est pas accessible en écriture "
                     . '(' . MAJ_DOSSIER . ') — le volume est-il monté ?'];
    }

    // Anti-rafale : le cron consomme la demande en moins d'une minute, une
    // demande plus ancienne signale que personne ne l'a ramassée.
    $enCours = majDemandeEnCours();
    if ($enCours) {
        $depuis = strtotime((string) ($enCours['demande_le'] ?? '')) ?: 0;
        if (time() - $depuis < MAJ_DELAI) {
            return [false, 'Une demande est déjà en attente de traitement'];
        }
    }

    $contenu = json_encode([
        'action'     => $action,
        'demande_le' => date('c'),
        'par'        => mb_substr($par, 0, 50),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $temporaire = MAJ_DOSSIER . '/.demande.tmp';
    if (@file_put_contents($temporaire, $contenu) === false || !@rename($temporaire, MAJ_DEMANDE)) {
        @unlink($temporaire);
        return [false, "Impossible d'écrire la demande"];
    }

    return [true, 'Demande déposée — le script de l\'hôte la traitera dans la minute'];
}
