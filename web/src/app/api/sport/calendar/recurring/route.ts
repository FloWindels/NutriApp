import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/sport/calendar/recurring", { label: "sport" });
