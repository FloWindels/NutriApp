import { proxyParamRoute } from "@/lib/laravel-proxy";

export const PUT = proxyParamRoute<{ id: string }>(({ id }) => `/stocks/items/${id}`, { label: "stock" });
export const DELETE = proxyParamRoute<{ id: string }>(({ id }) => `/stocks/items/${id}`, { label: "stock" });
