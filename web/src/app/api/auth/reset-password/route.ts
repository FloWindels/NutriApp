import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/reset-password", { label: "d’authentification" });
