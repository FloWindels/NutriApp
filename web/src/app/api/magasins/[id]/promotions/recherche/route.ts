import { proxyParamRoute } from "@/lib/laravel-proxy";

// Le relevé enchaîne une recherche web et un modèle : le délai par défaut de quinze secondes
// couperait la réponse avant qu'elle arrive.
export const POST = proxyParamRoute<{ id: string }>(
  ({ id }) => `/magasins/${id}/promotions/recherche`,
  { label: "des promotions", timeoutMs: 120000 },
);
