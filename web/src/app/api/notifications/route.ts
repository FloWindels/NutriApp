import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/notifications", { label: "des notifications" });
