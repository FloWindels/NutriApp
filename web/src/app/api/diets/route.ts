import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/diets", { label: "des régimes" });
