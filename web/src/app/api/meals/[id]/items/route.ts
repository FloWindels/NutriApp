import { proxyParamRoute } from "@/lib/laravel-proxy";

export const POST = proxyParamRoute<{ id: string }>(({ id }) => `/meals/${id}/items`, { label: "repas" });
