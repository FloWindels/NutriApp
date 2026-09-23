import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/foods/favorites", { label: "aliments" });
