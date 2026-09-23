import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/stocks/alerts", { label: "stock" });
