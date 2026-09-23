import { proxyParamRoute } from "@/lib/laravel-proxy";

export const PUT = proxyParamRoute<{ id: string }>(({ id }) => `/shopping-list/items/${id}`, { label: "de la liste de courses" });
export const DELETE = proxyParamRoute<{ id: string }>(({ id }) => `/shopping-list/items/${id}`, { label: "de la liste de courses" });
