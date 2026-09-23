import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/shopping-list", { label: "de la liste de courses" });
