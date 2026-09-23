import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/history", { label: "de l’historique", forwardQuery: true });
