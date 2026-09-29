import { formatDate, formatTime } from "@/lib/format";
import type { MealType } from "@/lib/types/api";
import { MEAL_TYPE_LABELS } from "@/lib/vocab";

/**
 * Le serveur envoie des clés techniques (`kcal_par_portion`, `jours_restants`).
 * Elles nous parlent, à nous ; pas à la personne qui déplie « Pourquoi ? ».
 * On traduit ici, au plus près de l’écran, pour ne pas figer du français
 * dans une base que d’autres clients relisent.
 */
const LIBELLES: Record<string, string> = {
  budget_repas: "Budget du repas",
  calories_bonus_sport: "Calories gagnées par le sport",
  calories_cibles: "Calories visées",
  calories_consommees: "Calories déjà mangées",
  calories_restantes: "Calories restantes",
  completed_at: "Séance terminée à",
  depassement: "Dépassement",
  duration_min: "Durée",
  expires_at: "À consommer avant le",
  expiry_kind: "Type de date",
  facteur_portion: "Portion ajustée",
  fenetre_kcal_max: "Haut de la fourchette",
  fenetre_kcal_min: "Bas de la fourchette",
  glucides_g: "Glucides",
  glucides_g_par_kg: "Glucides par kilo de poids",
  heure: "Heure",
  jours_alerte_peremption: "Alerte péremption réglée sur",
  jours_depasses: "Date dépassée depuis",
  jours_restants: "Jours restants",
  kcal_idee: "Calories de l’idée",
  kcal_par_portion: "Calories par portion",
  meal_type: "Repas",
  part_restante: "Part restante",
  plancher_kcal: "Plancher calorique",
  planned_at: "Séance prévue à",
  poids_kg: "Poids",
  produits: "Produits concernés",
  proteines_apres_seance: "Protéines après la séance",
  proteines_cibles: "Protéines visées",
  proteines_consommees: "Protéines déjà mangées",
  proteines_g: "Protéines",
  proteines_manquantes: "Protéines manquantes",
  repas_enregistres: "Repas enregistrés",
  seances_du_jour: "Séances du jour",
  session_id: "Séance",
  tolerance: "Tolérance",
  utilise_stock_expirant: "Utilise un produit qui périme",
};

/** Les seules valeurs qui sont un code et non un nombre ou un nom. */
const VALEURS: Record<string, Record<string, string>> = {
  expiry_kind: {
    dlc: "date limite de consommation",
    ddm: "date de durabilité minimale",
  },
  meal_type: MEAL_TYPE_LABELS as Record<MealType, string>,
};

function libelle(cle: string): string {
  const connu = LIBELLES[cle];
  if (connu) return connu;

  // Une clé ajoutée côté serveur sans passer par cette table doit rester lisible :
  // mieux vaut « facteur inconnu » écrit en clair qu’un `snake_case` jeté à l’écran.
  const lisible = cle.replace(/_/g, " ").trim();
  return lisible ? lisible.charAt(0).toUpperCase() + lisible.slice(1) : "";
}

const ISO_JOUR = /^\d{4}-\d{2}-\d{2}$/;
const ISO_INSTANT = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/;

function valeur(cle: string, brut: unknown, unite?: string): string {
  if (brut === null || brut === undefined || brut === "") return "";

  const code = VALEURS[cle]?.[String(brut)];
  if (code) return unite ? `${code} ${unite}` : code;

  if (Array.isArray(brut)) return brut.join(", ");

  // Le message juste au-dessus dit « depuis le 19 septembre » : laisser « 2026-09-19 »
  // sous le même conseil donnerait deux fois la même date dans deux langues.
  if (typeof brut === "string") {
    if (ISO_INSTANT.test(brut)) return `${formatDate(brut)} à ${formatTime(brut)}`;
    if (ISO_JOUR.test(brut)) return formatDate(brut);
  }

  const texte = String(brut);
  return unite ? `${texte} ${unite}` : texte;
}

/**
 * Rend un facteur en une ligne lisible. Une ligne vide est rendue vide pour que
 * l’appelant la filtre : mieux vaut un facteur en moins qu’une puce orpheline.
 */
export function texteFacteur(facteur: unknown): string {
  if (typeof facteur === "string") return facteur;
  if (typeof facteur === "number") return String(facteur);
  if (!facteur || typeof facteur !== "object") return "";

  const brut = facteur as { label?: unknown; name?: unknown; value?: unknown; unit?: unknown };
  const cle = typeof brut.label === "string" ? brut.label : typeof brut.name === "string" ? brut.name : "";
  const unite = typeof brut.unit === "string" ? brut.unit : undefined;

  if (!cle) {
    // Forme inattendue : on montre ce qu’on a plutôt que de faire disparaître
    // l’explication — et surtout jamais l’objet lui-même, que React refuse de rendre.
    return Object.values(brut as Record<string, unknown>)
      .filter((v) => v !== null && v !== undefined && typeof v !== "object")
      .map((v) => String(v))
      .join(" · ");
  }

  const rendu = valeur(cle, brut.value, unite);
  return rendu ? `${libelle(cle)} : ${rendu}` : libelle(cle);
}

/** Rend une liste de facteurs, les lignes vides en moins. */
export function textesFacteurs(facteurs: readonly unknown[] | null | undefined): string[] {
  return (facteurs ?? []).map(texteFacteur).filter(Boolean);
}
