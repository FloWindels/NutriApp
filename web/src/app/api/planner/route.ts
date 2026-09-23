import { proxyRoute } from "@/lib/laravel-proxy";

export const GET = proxyRoute("/planner", { label: "du planificateur", forwardQuery: true });
export const POST = proxyRoute("/planner", { label: "du planificateur" });
