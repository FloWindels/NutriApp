import { proxyParamRoute } from "@/lib/laravel-proxy";

export const GET = proxyParamRoute<{ id: string }>(({ id }) => `/recipes/${id}`, { label: "recettes" });
export const PUT = proxyParamRoute<{ id: string }>(({ id }) => `/recipes/${id}`, { label: "recettes", timeoutMs: 30000, maxBytes: 14_000_000 });
export const DELETE = proxyParamRoute<{ id: string }>(({ id }) => `/recipes/${id}`, { label: "recettes" });
