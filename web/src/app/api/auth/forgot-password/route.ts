import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/forgot-password", { label: "d’authentification" });
