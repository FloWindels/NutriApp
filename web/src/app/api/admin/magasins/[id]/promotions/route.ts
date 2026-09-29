import { proxyParamRoute } from "@/lib/laravel-proxy";

// Écrire une promotion est un geste d'administration : elle pèse sur le panier estimé de tous
// les clients de l'enseigne, pas sur la liste d'une seule personne.
export const POST = proxyParamRoute<{ id: string }>(({ id }) => `/admin/magasins/${id}/promotions`, {
  label: "d’administration",
});
