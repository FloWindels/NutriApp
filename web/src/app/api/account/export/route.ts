import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/account/export", { label: "du compte", timeoutMs: 30000 });
