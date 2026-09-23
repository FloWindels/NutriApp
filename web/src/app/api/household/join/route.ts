import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/household/join", { label: "du foyer" });
