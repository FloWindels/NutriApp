import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/login", { label: "d’authentification" });
