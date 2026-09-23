import { proxyParamRoute } from "@/lib/laravel-proxy";

export const POST = proxyParamRoute<{ id: string }>(({ id }) => `/sport/sessions/${id}/complete`, { label: "sport" });
