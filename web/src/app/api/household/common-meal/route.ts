import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/household/common-meal", { label: "du foyer" });
