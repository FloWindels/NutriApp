import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/household", { label: "du foyer" });
export const POST = proxyRoute("/household", { label: "du foyer" });
export const PUT = proxyRoute("/household", { label: "du foyer" });
export const DELETE = proxyRoute("/household", { label: "du foyer" });
