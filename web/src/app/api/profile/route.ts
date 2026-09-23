import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/profile", { label: "du profil" });
export const PUT = proxyRoute("/profile", { label: "du profil" });
