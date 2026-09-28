import { proxyParamRoute } from "@/lib/laravel-proxy";

export const POST = proxyParamRoute<{ id: string }>(({ id }) => `/admin/moderation/recipes/${id}/hide`, { label: "d’administration" });
