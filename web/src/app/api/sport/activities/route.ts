import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/sport/activities", { label: "sport" });
