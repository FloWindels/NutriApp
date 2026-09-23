import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/sport/sessions", { label: "sport", forwardQuery: true });
export const POST = proxyRoute("/sport/sessions", { label: "sport" });
