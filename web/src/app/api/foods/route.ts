import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/foods", { label: "aliments" });
