import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/sport/sports", { label: "sport", forwardQuery: true });
export const POST = proxyRoute("/sport/sports", { label: "sport" });
