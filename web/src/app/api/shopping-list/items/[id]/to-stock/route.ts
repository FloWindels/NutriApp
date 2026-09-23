import { proxyParamRoute } from "@/lib/laravel-proxy";

export const POST = proxyParamRoute<{ id: string }>(({ id }) => `/shopping-list/items/${id}/to-stock`, { label: "de la liste de courses" });
