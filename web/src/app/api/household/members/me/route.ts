import { proxyRoute } from "@/lib/laravel-proxy";

export const PUT = proxyRoute("/household/members/me", { label: "du foyer" });
