"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useEffect, useState, type ReactNode } from "react";
import { ToastProvider } from "@/components/ui/toast";
import { isApiError } from "@/lib/api-client";

function makeQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30 * 1000,
        gcTime: 5 * 60 * 1000,
        refetchOnWindowFocus: false,
        // Never hammer the API on 4xx (401 already redirected, 404/422 are final).
        retry: (failureCount, error) => !(isApiError(error) && error.status < 500) && failureCount < 1,
      },
      mutations: {
        retry: false,
      },
    },
  });
}

export function Providers({ children }: { children: ReactNode }) {
  // One client per browser session; created lazily so it is never shared between requests.
  const [queryClient] = useState(makeQueryClient);

  // Application installable : le service worker (public/sw.js) ne garde que les fichiers de
  // l'application et la page hors ligne, jamais de données. En production seulement.
  useEffect(() => {
    if (process.env.NODE_ENV !== "production" || !("serviceWorker" in navigator)) return;
    navigator.serviceWorker.register("/sw.js").catch(() => {
      // Navigation privée ou navigateur réticent : le site fonctionne sans.
    });
  }, []);

  return (
    <QueryClientProvider client={queryClient}>
      <ToastProvider>{children}</ToastProvider>
    </QueryClientProvider>
  );
}

export default Providers;
