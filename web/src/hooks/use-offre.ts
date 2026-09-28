"use client";

import { useMe } from "@/hooks/use-me";
import { useSession } from "@/lib/session";
import type { Me, OffreCourante } from "@/lib/types/api";

/**
 * Ce que l'offre du compte autorise, côté écran.
 *
 * Le serveur reste le seul juge : il répond 402 quoi qu'affiche le navigateur. Ce que ce
 * hook permet, c'est d'expliquer le verrou avant de se le prendre, plutôt que de laisser
 * quelqu'un remplir un formulaire pour rien.
 *
 * Tant que `/me` n'a pas répondu, on ne prétend rien : `pret` vaut false et `peut()` renvoie
 * true, pour ne pas afficher un verrou à quelqu'un qui a payé.
 */
export function useOffre(): {
  offre: OffreCourante | null;
  pret: boolean;
  peut: (capacite: string) => boolean;
} {
  const { user } = useSession();
  const query = useMe();

  const offre = offreDe(query.data) ?? offreDe(user);

  return {
    offre,
    pret: offre !== null,
    peut: (capacite: string) => (offre === null ? true : offre.capacites.includes(capacite)),
  };
}

function offreDe(user: unknown): OffreCourante | null {
  const offre = (user as Me | null | undefined)?.offre;

  return offre && Array.isArray(offre.capacites) ? offre : null;
}
