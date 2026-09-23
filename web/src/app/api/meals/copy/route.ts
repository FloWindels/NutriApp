import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/meals/copy", { label: "repas" });
