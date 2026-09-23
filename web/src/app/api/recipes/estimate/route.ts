import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/recipes/estimate", { label: "recettes" });
