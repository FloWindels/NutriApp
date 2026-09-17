"use client";

import { useQuery } from "@tanstack/react-query";
import { useEffect } from "react";
import { apiGet, isApiError } from "@/lib/api-client";
import { queryKeys } from "@/lib/query-keys";
import { setSessionUser, useSession } from "@/lib/session";
import type { Me } from "@/lib/types/api";

/**
 * `GET /me` bound to the session: refreshes the cached user, never retries on 4xx.
 * A 401 is handled by `apiFetch` (clears the session and redirects to `/login?expired=1`).
 */
export function useMe(options: { enabled?: boolean } = {}) {
  const { token, ready } = useSession();
  const enabled = (options.enabled ?? true) && ready && Boolean(token);

  const query = useQuery({
    queryKey: queryKeys.me,
    queryFn: () => apiGet<Me>("/auth/me"),
    enabled,
    staleTime: 2 * 60 * 1000,
    retry: (failureCount, error) => !(isApiError(error) && error.status < 500) && failureCount < 1,
  });

  useEffect(() => {
    if (query.data) setSessionUser(query.data);
  }, [query.data]);

  return query;
}
