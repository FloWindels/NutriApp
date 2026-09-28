import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/admin/codes", { label: "d’administration" });
export const POST = proxyRoute("/admin/codes", { label: "d’administration" });
