import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/recipes", { label: "recettes", forwardQuery: true });
export const POST = proxyRoute("/recipes", { label: "recettes", timeoutMs: 30000 });
