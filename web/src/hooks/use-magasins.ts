"use client";

import { useQuery } from "@tanstack/react-query";
import { apiGet } from "@/lib/api-client";
import { queryKeys } from "@/lib/query-keys";
import type { Magasin, MagasinsResponse, Rayon } from "@/lib/types/api";

/**
 * Le catalogue des enseignes et des rayons, tel que le serveur le donne.
 *
 * Il est public et ne change pour ainsi dire jamais : on le garde une heure plutôt que de le
 * redemander à chaque ouverture de la liste de courses. Les rayons arrivent déjà dans l'ordre de
 * traversée du magasin, celui dans lequel on veut lire sa liste ; ne les retrie nulle part.
 */
export function useMagasins(): {
  magasins: Magasin[];
  rayons: Rayon[];
  avertissement: string;
  chargement: boolean;
} {
  const query = useQuery({
    queryKey: queryKeys.magasins.catalogue,
    queryFn: () => apiGet<MagasinsResponse>("/magasins", undefined, { anonymous: true }),
    staleTime: 60 * 60 * 1000,
    gcTime: 60 * 60 * 1000,
    retry: 1,
  });

  return {
    magasins: query.data?.data ?? [],
    rayons: query.data?.rayons ?? [],
    avertissement: query.data?.avertissement ?? "",
    chargement: query.isPending,
  };
}

/** Le magasin d'un identifiant, ou `null` — y compris pendant le chargement du catalogue. */
export function magasinParId(magasins: Magasin[], id: number | null | undefined): Magasin | null {
  if (id === null || id === undefined) return null;

  return magasins.find((magasin) => magasin.id === id) ?? null;
}
