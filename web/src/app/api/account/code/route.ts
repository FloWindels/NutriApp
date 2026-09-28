import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/account/code", { label: "du compte" });
