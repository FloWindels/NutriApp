import { proxyParamRoute } from "@/lib/laravel-proxy";

export const PUT = proxyParamRoute<{ id: string }>(({ id }) => `/meals/${id}`, { label: "repas" });
export const DELETE = proxyParamRoute<{ id: string }>(({ id }) => `/meals/${id}`, { label: "repas" });
