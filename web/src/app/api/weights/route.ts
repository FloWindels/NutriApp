import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/weights", { label: "du poids", forwardQuery: true });
export const POST = proxyRoute("/weights", { label: "du poids" });
