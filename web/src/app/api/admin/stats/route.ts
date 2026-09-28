import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/admin/stats", { label: "d’administration", forwardQuery: true });
