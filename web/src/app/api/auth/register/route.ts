import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/register", { label: "d’authentification" });
