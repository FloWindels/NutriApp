import { proxyRoute } from "@/lib/laravel-proxy";

export const POST = proxyRoute("/planner/generate", { label: "du planificateur", timeoutMs: 30000 });
