import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/logout", { label: "d’authentification" });
