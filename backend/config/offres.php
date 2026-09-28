<?php

use App\Enums\Offre;

/**
 * Découpage des offres.
 *
 * Une capacité est nommée ici et nulle part ailleurs : le middleware `offre`, les contrôleurs
 * et l'interface s'y réfèrent par ce nom. Déplacer une fonctionnalité d'une offre à l'autre est
 * donc une ligne à changer dans ce fichier, pas une chasse dans tout le code.
 */
return [

    /*
    | stock          — gestion du garde-manger, alertes de péremption, anti-gaspillage
    | courses        — liste de courses partagée et sa génération
    | planificateur  — planification des repas de la semaine
    | coach          — conseils du jour (moteur de règles, sans IA)
    | seances        — le coach sportif propose une séance : par l'IA comme par les règles
    | regimes        — évaluation du régime reconnu
    | ia             — séances, recettes et photo d'assiette générées par un modèle
    | foyer          — partage avec les autres membres du ménage
    */
    'capacites' => [
        // Le suivi normal : repas, aliments, code-barres, recettes personnelles, poids, profil,
        // paramètres, historique, et le suivi sportif — enregistrer ses séances et ses activités,
        // tenir son calendrier. Ce qui est payant, c'est le coach qui PROPOSE une séance.
        //
        // Le coach du jour y figure délibérément. C'est un moteur de règles qui ne coûte rien à
        // faire tourner, et c'est le meilleur argument d'abonnement du produit : on ne paie que
        // ce dont on a compris l'intérêt. Ses conseils mènent naturellement vers le stock et le
        // planificateur, qui eux sont payants.
        Offre::Gratuit->value => ['coach'],

        Offre::Complet->value => ['stock', 'courses', 'planificateur', 'coach', 'seances', 'regimes', 'ia'],

        Offre::Foyer->value => ['stock', 'courses', 'planificateur', 'coach', 'seances', 'regimes', 'ia', 'foyer'],
    ],

    /** Tarifs indicatifs, affichés par le site. Aucun paiement n'est encore branché. */
    'tarifs' => [
        Offre::Gratuit->value => ['mensuel' => 0.0, 'annuel' => 0.0],
        Offre::Complet->value => ['mensuel' => 4.99, 'annuel' => 39.99],
        Offre::Foyer->value => ['mensuel' => 7.99, 'annuel' => 59.99],
    ],

    /** Nombre de membres qu'un foyer peut réunir sous l'offre Foyer. */
    'foyer_membres_max' => 5,
];
