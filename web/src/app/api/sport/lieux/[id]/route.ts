import { proxyParamRoute } from "@/lib/laravel-proxy";

export const PUT = proxyParamRoute<{ id: string }>(({ id }) => `/sport/lieux/${id}`, {
  label: "lieux d’entraînement",
});
export const DELETE = proxyParamRoute<{ id: string }>(({ id }) => `/sport/lieux/${id}`, {
  label: "lieux d’entraînement",
});
