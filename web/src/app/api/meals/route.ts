import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/meals", { label: "repas", forwardQuery: true });
export const POST = proxyRoute("/meals", { label: "repas" });
