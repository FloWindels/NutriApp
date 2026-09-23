import { proxyParamRoute } from "@/lib/laravel-proxy";

export const PUT = proxyParamRoute<{ id: string }>(({ id }) => `/planner/${id}`, { label: "du planificateur" });
export const DELETE = proxyParamRoute<{ id: string }>(({ id }) => `/planner/${id}`, { label: "du planificateur" });
