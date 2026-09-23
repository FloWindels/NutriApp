import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/sport/exercises", { label: "sport", forwardQuery: true });
