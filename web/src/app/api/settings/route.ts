import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/settings", { label: "des paramètres" });
export const PUT = proxyRoute("/settings", { label: "des paramètres" });
