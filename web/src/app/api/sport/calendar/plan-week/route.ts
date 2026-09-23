import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/sport/calendar/plan-week", { label: "sport", timeoutMs: 120000 });
