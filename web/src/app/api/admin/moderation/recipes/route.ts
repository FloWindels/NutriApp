import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/admin/moderation/recipes", { label: "d’administration", forwardQuery: true });
