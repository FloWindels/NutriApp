import { proxyRoute } from "@/lib/laravel-proxy";

export const DELETE = proxyRoute("/shopping-list/checked", { label: "de la liste de courses" });
