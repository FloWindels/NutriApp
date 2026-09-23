import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/sport/summary", { label: "sport", forwardQuery: true });
