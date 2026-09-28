import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/admin/moderation/foods", { label: "d’administration", forwardQuery: true });
