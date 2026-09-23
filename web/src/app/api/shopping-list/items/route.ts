import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/shopping-list/items", { label: "de la liste de courses" });
