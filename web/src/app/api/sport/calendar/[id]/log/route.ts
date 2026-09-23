import { proxyParamRoute } from "@/lib/laravel-proxy";

export const POST = proxyParamRoute<{ id: string }>(({ id }) => `/sport/calendar/${id}/log`, { label: "sport" });
