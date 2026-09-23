import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/diets/evaluate", { label: "des régimes", forwardQuery: true });
