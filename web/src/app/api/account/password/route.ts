import { proxyRoute } from "@/lib/laravel-proxy";

export const PUT = proxyRoute("/account/password", { label: "du compte" });
