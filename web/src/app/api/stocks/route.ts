import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/stocks", { label: "stock", forwardQuery: true });
export const POST = proxyRoute("/stocks", { label: "stock" });
