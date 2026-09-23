import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/me", { label: "d’authentification" });
