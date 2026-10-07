"use client";

import { usePathname, useRouter } from "next/navigation";
import { useEffect } from "react";
import { useQuestionnaireAttendu, useVisiteEnCours } from "./use-accueil";

/**
 * Première connexion : de l'accueil, on emmène directement vers le questionnaire tant que le
 * profil n'est pas rempli. Seul `/dashboard` redirige — l'inscription et la connexion y
 * aboutissent — pour ne jamais arracher quelqu'un à une page qu'il a choisi d'ouvrir. La visite
 * guidée, qui passe par l'accueil en naviguant d'elle-même, n'est jamais interrompue.
 */
export function GardeAccueil() {
  const pathname = usePathname();
  const router = useRouter();
  const attendu = useQuestionnaireAttendu();
  const visiteEnCours = useVisiteEnCours();

  useEffect(() => {
    if (attendu && !visiteEnCours && pathname === "/dashboard") router.replace("/dashboard/bienvenue");
  }, [attendu, visiteEnCours, pathname, router]);

  return null;
}
