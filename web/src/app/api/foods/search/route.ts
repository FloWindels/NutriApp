import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/foods/search", { label: "aliments", timeoutMs: 20000, forwardQuery: true });
