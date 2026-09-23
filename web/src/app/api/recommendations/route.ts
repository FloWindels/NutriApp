import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/recommendations", { label: "du coach", forwardQuery: true });
