import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/portions", { label: "aliments" });
