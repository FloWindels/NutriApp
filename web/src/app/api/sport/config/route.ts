import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/sport/config", { label: "sport" });
