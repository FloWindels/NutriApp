import { proxyParamRoute } from "@/lib/laravel-proxy";

export const POST = proxyParamRoute<{ id: string }>(({ id }) => `/admin/codes/${id}/revoke`, { label: "d’administration" });
