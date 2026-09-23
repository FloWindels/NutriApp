import { proxyParamRoute } from "@/lib/laravel-proxy";

export const GET = proxyParamRoute<{ id: string }>(({ id }) => `/foods/${id}`, { label: "aliments" });
export const PUT = proxyParamRoute<{ id: string }>(({ id }) => `/foods/${id}`, { label: "aliments" });
