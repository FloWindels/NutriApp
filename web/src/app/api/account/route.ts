import { proxyRoute } from "@/lib/laravel-proxy";

export const PUT = proxyRoute("/account", { label: "du compte" });
export const DELETE = proxyRoute("/account", { label: "du compte" });
