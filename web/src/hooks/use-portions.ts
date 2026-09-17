"use client";

import { useQuery } from "@tanstack/react-query";
import { apiGet } from "@/lib/api-client";
import { queryKeys } from "@/lib/query-keys";
import type { Portion, PortionsResponse } from "@/lib/types/api";
import { PORTIONS_FALLBACK } from "@/lib/units";

/** Units table from `GET /portions` with the client fallback while loading / offline. */
export function usePortions(): { portions: Portion[]; aliases: Record<string, string>; isFallback: boolean } {
  const query = useQuery({
    queryKey: queryKeys.portions,
    queryFn: () => apiGet<PortionsResponse>("/portions", undefined, { anonymous: true }),
    staleTime: 24 * 60 * 60 * 1000,
    gcTime: 24 * 60 * 60 * 1000,
    retry: 1,
  });

  const portions = query.data?.data?.length ? query.data.data : PORTIONS_FALLBACK;
  return { portions, aliases: query.data?.aliases ?? {}, isFallback: !query.data };
}
