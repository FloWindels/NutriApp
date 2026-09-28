/**
 * Versions des textes légaux.
 *
 * Ces constantes sont enregistrées avec l'accord de chaque personne à l'inscription : elles
 * répondent à l'obligation de l'article 7.1 du RGPD, qui impose de pouvoir démontrer non
 * seulement QU'une personne a consenti, mais à QUOI elle a consenti.
 *
 * Règle : toute modification de fond d'un de ces textes change sa version. Les comptes créés
 * sous une version antérieure sont alors invités à prendre connaissance du nouveau texte.
 * Une correction de faute d'orthographe ne change pas la version.
 */

export const VERSION_CGU = "2026-09-29";
export const VERSION_CONFIDENTIALITE = "2026-09-29";
export const VERSION_MENTIONS = "2026-09-29";

/** Affichée en tête de chaque document. */
export function dateVersion(version: string): string {
  const [annee, mois, jour] = version.split("-");
  return `${jour}/${mois}/${annee}`;
}
