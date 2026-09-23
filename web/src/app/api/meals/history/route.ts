import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/meals/history", { label: "repas", forwardQuery: true });
