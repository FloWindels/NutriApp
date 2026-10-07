import { proxyRoute } from "@/lib/laravel-proxy";

// Un agent lit plusieurs pages avant de répondre : jusqu'à une minute, on laisse de la marge.
export const POST = proxyRoute("/foods/recherche-en-ligne", { label: "de la recherche en ligne", timeoutMs: 120000 });
