import { proxyParamRoute } from "@/lib/laravel-proxy";

export const PUT = proxyParamRoute<{ key: string }>(({ key }) => `/notifications/${key}/read`, { label: "des notifications" });
