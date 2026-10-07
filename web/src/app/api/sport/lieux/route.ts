import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/sport/lieux", { label: "lieux d’entraînement" });
export const POST = proxyRoute("/sport/lieux", { label: "lieux d’entraînement" });
