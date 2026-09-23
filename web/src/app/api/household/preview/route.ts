import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/household/preview", { label: "du foyer", forwardQuery: true });
