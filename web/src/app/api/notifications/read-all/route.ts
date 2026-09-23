import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/notifications/read-all", { label: "des notifications" });
