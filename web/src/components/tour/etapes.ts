/**
 * Les étapes de la visite guidée.
 *
 * Chaque étape ouvre la vraie page de la fonctionnalité (`route`), puis éclaire un élément
 * de cette page repéré par son attribut `data-tour`.
 *
 * - `cibles` : repères attendus, dans l'ordre de préférence ; le premier visible est retenu dès
 *   qu'il apparaît (la page charge parfois ses données d'abord).
 * - `replis` : repères de secours propres à la page, retenus seulement si aucune cible n'est
 *   apparue à temps (état vide, erreur…). Viennent ensuite la carte « offre requise » d'une
 *   page verrouillée et l'en-tête de la page — sauf avec `sansRepli`, où l'étape se contente
 *   alors d'une carte centrée.
 *
 * `capacite` désigne la fonctionnalité payante dont parle l'étape (voir
 * backend/config/offres.php). Un compte qui ne l'a pas visite la même page, avec la mention de
 * l'offre qui l'inclut : on montre tout ce que Mavi'oh sait faire, on ne cache rien.
 */
export type EtapeVisite = {
  id: string;
  titre: string;
  texte: string;
  route: string;
  cibles: string[];
  replis?: string[];
  sansRepli?: boolean;
  capacite?: string;
  /** Ce qui reste accessible sans l'offre, quand l'étape n'est payante qu'en partie. */
  sansOffre?: string;
};

export const ACCUEIL = "/dashboard";

export const ETAPES_VISITE: EtapeVisite[] = [
  {
    id: "bienvenue",
    titre: "Bienvenue sur Mavi’oh",
    texte:
      "Ton coach nutrition et sport. En une minute, on fait le tour des pages. Tu pourras revoir cette visite depuis les Paramètres.",
    route: ACCUEIL,
    cibles: [],
  },
  {
    id: "repas",
    titre: "Ton journal des repas",
    texte:
      "Note chaque repas avec « Ajouter » : un aliment, une recette, ou ton assiette en photo, que Mavi’oh découpe en aliments et en calories.",
    route: "/dashboard/historique-repas-journee",
    cibles: ["repas-du-jour"],
    capacite: "ia",
    sansOffre: "Le journal est gratuit ; la photo d’assiette fait partie de l’offre",
  },
  {
    id: "aliments",
    titre: "Trouver un aliment",
    texte:
      "Cherche parmi des milliers d’aliments par leur nom ou leur marque, ou tape le code-barres d’un produit pour retrouver ses valeurs nutritionnelles.",
    route: "/dashboard/recherche-aliments",
    cibles: ["recherche-aliment"],
  },
  {
    id: "recettes",
    titre: "Tes recettes",
    texte:
      "Enregistre tes recettes : Mavi’oh calcule les calories par portion. « Avec ce que j’ai » t’en écrit une à partir de ton stock.",
    route: "/dashboard/recettes",
    cibles: ["recettes-actions"],
    capacite: "ia",
    sansOffre: "Tes recettes sont gratuites ; celles écrites par Mavi’oh font partie de l’offre",
  },
  {
    id: "coach",
    titre: "Le coach du jour",
    texte:
      "Chaque jour, Mavi’oh regarde ce que tu as mangé et te dit quoi ajuster au repas suivant : la protéine qui manque, l’écart à rattraper.",
    route: "/dashboard/recommandations-repas-journee",
    cibles: ["coach-conseil"],
    replis: ["coach-controles"],
    capacite: "coach",
  },
  {
    id: "stock",
    titre: "Ton frigo et tes placards",
    texte:
      "Range ce que tu as chez toi : Mavi’oh te prévient avant qu’un produit ne périme et compose tes repas avec.",
    route: "/dashboard/stock",
    cibles: ["stock-ajouter"],
    capacite: "stock",
  },
  {
    id: "planificateur",
    titre: "Ta semaine planifiée",
    texte:
      "« Générer la semaine » remplit tes repas en un geste, selon tes objectifs, ton régime et ce que tu as sous la main.",
    route: "/dashboard/planificateur-semaine",
    cibles: ["planificateur-generer"],
    capacite: "planificateur",
  },
  {
    id: "courses",
    titre: "La liste de courses",
    texte:
      "Générée depuis ton planning, moins ce que tu as déjà. Choisis ton magasin pour la voir rangée par rayon, avec des prix indicatifs.",
    route: "/dashboard/liste-course",
    cibles: ["courses-generer"],
    capacite: "courses",
  },
  {
    id: "sport",
    titre: "Le sport",
    texte:
      "Enregistre tes activités : les calories brûlées s’ajoutent à ton budget. Mavi’oh peut aussi construire ta séance selon ton niveau, ton matériel et ton temps.",
    route: "/dashboard/sport",
    cibles: ["sport-generer"],
    replis: ["sport-onglets"],
    capacite: "seances",
    sansOffre: "Enregistrer tes activités est gratuit ; les séances construites par Mavi’oh font partie de l’offre",
  },
  {
    id: "famille",
    titre: "En famille",
    texte:
      "Invite jusqu’à cinq personnes : stock, courses et repas planifiés partagés, et un repas commun dont Mavi’oh calcule la part de chacun.",
    route: "/dashboard/famille",
    cibles: ["famille-invitation", "famille-creer", "famille-rejoindre", "famille-membres"],
    capacite: "foyer",
  },
  {
    id: "profil",
    titre: "Ton profil",
    texte:
      "Tes objectifs naissent de ton profil : poids, objectif, régime, allergies. Mets-le à jour quand ta situation change.",
    route: "/dashboard/profil",
    cibles: ["profil-informations"],
  },
  {
    id: "parametres",
    titre: "Les paramètres",
    texte:
      "Rappels, alertes de péremption, magasin habituel, export de tes données… et ce bouton pour revoir la visite.",
    route: "/dashboard/settings",
    cibles: ["parametres-decouvrir"],
  },
  {
    id: "installer",
    titre: "Mavi’oh sur ton téléphone",
    texte:
      "Sur iPhone, dans Safari : Partager puis « Sur l’écran d’accueil », et l’app s’ouvre en plein écran. Sur Android : « Installer l’application » dans le menu du navigateur.",
    route: ACCUEIL,
    cibles: ["installer-app"],
    sansRepli: true,
  },
  {
    id: "fin",
    titre: "À toi de jouer",
    texte: "Commence par noter ton prochain repas : le reste viendra naturellement.",
    route: ACCUEIL,
    cibles: [],
  },
];
