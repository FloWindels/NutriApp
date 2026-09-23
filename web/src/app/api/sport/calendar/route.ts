import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/sport/calendar", { label: "sport", forwardQuery: true });
export const POST = proxyRoute("/sport/calendar", { label: "sport" });
