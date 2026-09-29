import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/magasins", { label: "des magasins" });
