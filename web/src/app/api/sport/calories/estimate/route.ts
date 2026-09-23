import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/sport/calories/estimate", { label: "sport" });
