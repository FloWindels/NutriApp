import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/household/common-meal/preview", { label: "du foyer" });
