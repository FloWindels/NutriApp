import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/profile/preview", { label: "du profil" });
