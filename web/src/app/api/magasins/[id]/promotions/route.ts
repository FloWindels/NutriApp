import { proxyParamRoute } from "@/lib/laravel-proxy";

export const GET = proxyParamRoute<{ id: string }>(({ id }) => `/magasins/${id}/promotions`, {
  label: "des promotions",
});
