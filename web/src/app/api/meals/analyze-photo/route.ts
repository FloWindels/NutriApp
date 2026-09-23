import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/meals/analyze-photo", { label: "de la photo", timeoutMs: 120000 });
