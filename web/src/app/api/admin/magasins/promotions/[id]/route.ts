import { proxyParamRoute } from "@/lib/laravel-proxy";

export const PUT = proxyParamRoute<{ id: string }>(({ id }) => `/admin/magasins/promotions/${id}`, {
  label: "d’administration",
});
export const DELETE = proxyParamRoute<{ id: string }>(({ id }) => `/admin/magasins/promotions/${id}`, {
  label: "d’administration",
});
