import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/shopping-list/generate", { label: "de la liste de courses", timeoutMs: 30000 });
