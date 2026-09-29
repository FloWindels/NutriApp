import { proxyParamRoute } from "@/lib/laravel-proxy";

export const PUT = proxyParamRoute<{ id: string }>(({ id }) => `/magasins/promotions/${id}`, {
  label: "des promotions",
});
export const DELETE = proxyParamRoute<{ id: string }>(({ id }) => `/magasins/promotions/${id}`, {
  label: "des promotions",
});
