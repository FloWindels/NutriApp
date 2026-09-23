import { proxyParamRoute } from "@/lib/laravel-proxy";

export const PUT = proxyParamRoute<{ id: string, itemId: string }>(({ id, itemId }) => `/meals/${id}/items/${itemId}`, { label: "repas" });
export const DELETE = proxyParamRoute<{ id: string, itemId: string }>(({ id, itemId }) => `/meals/${id}/items/${itemId}`, { label: "repas" });
