import { proxyParamRoute } from "@/lib/laravel-proxy";

export const GET = proxyParamRoute<{ id: string }>(({ id }) => `/sport/sessions/${id}`, { label: "sport" });
export const PUT = proxyParamRoute<{ id: string }>(({ id }) => `/sport/sessions/${id}`, { label: "sport" });
export const DELETE = proxyParamRoute<{ id: string }>(({ id }) => `/sport/sessions/${id}`, { label: "sport" });
