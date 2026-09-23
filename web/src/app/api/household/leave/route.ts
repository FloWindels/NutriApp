import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/household/leave", { label: "du foyer" });
