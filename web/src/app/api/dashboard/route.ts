import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/dashboard", { label: "du tableau de bord", forwardQuery: true });
