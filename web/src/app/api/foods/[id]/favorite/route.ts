import { proxyParamRoute } from "@/lib/laravel-proxy";

export const POST = proxyParamRoute<{ id: string }>(({ id }) => `/foods/${id}/favorite`, { label: "aliments" });
export const DELETE = proxyParamRoute<{ id: string }>(({ id }) => `/foods/${id}/favorite`, { label: "aliments" });
