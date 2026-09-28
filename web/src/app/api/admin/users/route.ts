import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/admin/users", { label: "d’administration", forwardQuery: true });
