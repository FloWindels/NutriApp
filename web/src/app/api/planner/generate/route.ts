import { proxyRoute } from "@/lib/laravel-proxy";

// Une semaine composée par un modèle met couramment 20 à 60 s : le délai des autres routes du
// planificateur la tuerait avant que le repli par règles ait eu lieu.
export const POST = proxyRoute("/planner/generate", { label: "du planificateur", timeoutMs: 120000 });
