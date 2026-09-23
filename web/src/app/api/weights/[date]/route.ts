import { proxyParamRoute } from "@/lib/laravel-proxy";

export const DELETE = proxyParamRoute<{ date: string }>(({ date }) => `/weights/${date}`, { label: "du poids" });
