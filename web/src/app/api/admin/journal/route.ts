import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/admin/journal", { label: "d’administration", forwardQuery: true });
