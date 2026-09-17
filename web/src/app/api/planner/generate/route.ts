import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

export async function POST(request: NextRequest) {
  return proxyToLaravel(request, "/planner/generate", { method: "POST", label: "du planificateur", timeoutMs: 30000 });
}
