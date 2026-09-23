import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/meals/frequent", { label: "repas" });
