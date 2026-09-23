import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/stocks/items", { label: "stock" });
