import { proxyParamRoute } from "@/lib/laravel-proxy";

export const PUT = proxyParamRoute<{ id: string }>(({ id }) => `/sport/calendar/${id}`, { label: "sport" });
export const DELETE = proxyParamRoute<{ id: string }>(({ id }) => `/sport/calendar/${id}`, { label: "sport", forwardQuery: true });
