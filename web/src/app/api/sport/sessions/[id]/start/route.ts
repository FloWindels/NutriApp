import { proxyParamRoute } from "@/lib/laravel-proxy";

export const POST = proxyParamRoute<{ id: string }>(({ id }) => `/sport/sessions/${id}/start`, { label: "sport" });
