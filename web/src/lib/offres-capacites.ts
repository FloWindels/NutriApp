import { isApiError } from "@/lib/api-client";

/**
 * Ce qu'on dit de chaque fonctionnalité payante quand quelqu'un tombe dessus.
 *
 * Le serveur nomme la capacité qui manque dans sa réponse 402 ; il ne dit pas ce qu'elle
 * apporte. C'est ici, et nulle part ailleurs, qu'on écrit l'argument — sinon chaque écran
 * improvise le sien et l'un d'eux finit par se contenter d'un refus.
 *
 * Règle d'écriture : on décrit ce que Mavi’oh fait pour toi, jamais ce que tu n'as pas le
 * droit de faire. Quelqu'un ne paie que ce dont il a compris l'intérêt.
 */

export type Argumentaire = {
  titre: string;
  argument: string;
  /** L'offre la moins chère qui débloque la capacité. */
  offre: string;
};

/** Les huit capacités de backend/config/offres.php, dans le même ordre. */
export const ARGUMENTAIRES: Record<string, Argumentaire> = {
  stock: {
    titre: "Ton stock, toujours à jour",
    argument:
      "Mavi’oh sait ce qu’il y a dans ton frigo, te prévient avant que ça périme et compose tes repas avec.",
    offre: "Complet",
  },
  courses: {
    titre: "Ta liste de courses se remplit toute seule",
    argument:
      "Mavi’oh additionne les ingrédients de tes repas de la semaine, retire ce que tu as déjà en stock et te laisse une liste prête à cocher dans les rayons.",
    offre: "Complet",
  },
  planificateur: {
    titre: "Ta semaine de repas, décidée à l’avance",
    argument:
      "Mavi’oh remplit tes créneaux de la semaine selon tes objectifs, ton régime et ce que tu as déjà sous la main, puis envoie les ingrédients à ta liste de courses. Tu ne te demandes plus ce que tu manges ce soir.",
    offre: "Complet",
  },
  coach: {
    titre: "Ton coach du jour",
    argument:
      "Chaque jour, Mavi’oh regarde ce que tu as mangé et te dit quoi ajuster au repas suivant : la protéine qui manque, l’écart à rattraper, l’objectif déjà atteint.",
    offre: "Complet",
  },
  seances: {
    titre: "Le coach sportif construit ta séance",
    argument:
      "Mavi’oh écrit la séance à ta place : les exercices, les séries, les charges et la durée, adaptés à ton niveau, à ton matériel et au temps dont tu disposes. Enregistrer une activité et tenir ton calendrier restent gratuits.",
    offre: "Complet",
  },
  regimes: {
    titre: "Ton régime, vérifié repas après repas",
    argument:
      "Mavi’oh compare ce que tu manges aux principes de ton régime, te donne un score de conformité et pointe les écarts jour par jour, avec de quoi les corriger.",
    offre: "Complet",
  },
  ia: {
    titre: "L’intelligence artificielle de Mavi’oh",
    argument:
      "Dis ce dont tu as envie et Mavi’oh écrit la recette ; photographie ton assiette et il la découpe en aliments et en calories ; il compose aussi tes séances de sport.",
    offre: "Complet",
  },
  foyer: {
    titre: "Tout le foyer sur la même page",
    argument:
      "Stock, liste de courses et repas planifiés partagés jusqu’à cinq personnes, et un repas commun dont Mavi’oh calcule la part de chacun selon ses objectifs.",
    offre: "Foyer",
  },
};

/**
 * Le repli sert quand le serveur ajoute une capacité que l'interface ne connaît pas encore.
 * Mieux vaut une invitation vague qu'un écran d'erreur pour une porte qui s'ouvre.
 */
const REPLI: Argumentaire = {
  titre: "Cette fonctionnalité fait partie des offres payantes",
  argument:
    "Elle existe et elle t’attend : il te suffit de passer à une offre qui l’inclut pour l’utiliser.",
  offre: "Complet",
};

export function argumentaire(capacite: string | null | undefined): Argumentaire {
  return (capacite && ARGUMENTAIRES[capacite]) || REPLI;
}

/** Une réponse 402 : la fonctionnalité existe, elle est simplement hors de l'offre du compte. */
export function estOffreRequise(error: unknown): boolean {
  return isApiError(error) && error.isOffreRequise;
}

/** La capacité que le serveur a nommée dans son 402, quand il l'a nommée. */
export function capaciteRequise(error: unknown): string | null {
  if (!estOffreRequise(error)) return null;

  const payload = (error as { payload?: unknown }).payload;
  const capacite = (payload as { capacite_requise?: unknown } | null)?.capacite_requise;

  return typeof capacite === "string" && capacite !== "" ? capacite : null;
}
