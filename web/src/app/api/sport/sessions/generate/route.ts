import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/sport/sessions/generate", { label: "sport", timeoutMs: 120000 });
