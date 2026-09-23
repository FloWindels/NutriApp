import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/meals/photo-capability", { label: "de la photo" });
