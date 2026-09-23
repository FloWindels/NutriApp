import { proxyParamRoute } from "@/lib/laravel-proxy";

export const PUT = proxyParamRoute<{ id: string }>(({ id }) => `/stocks/${id}`, { label: "stock" });
export const DELETE = proxyParamRoute<{ id: string }>(({ id }) => `/stocks/${id}`, { label: "stock" });
