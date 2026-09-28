import { proxyParamRoute } from "@/lib/laravel-proxy";

export const POST = proxyParamRoute<{ id: string }>(({ id }) => `/admin/moderation/recipes/${id}/show`, { label: "d’administration" });
