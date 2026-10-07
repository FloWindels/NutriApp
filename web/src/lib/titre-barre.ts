"use client";

import { createContext, useContext } from "react";

/**
 * Titre affiché dans la barre du haut sur téléphone. Un en-tête de page qui le répète mot pour
 * mot s'efface alors à l'écran (pas pour les lecteurs d'écran), comme dans l'application.
 */
export const TitreBarreContext = createContext<string | null>(null);

export function useTitreBarre(): string | null {
  return useContext(TitreBarreContext);
}
