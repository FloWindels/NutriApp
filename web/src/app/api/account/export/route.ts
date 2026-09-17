import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

export async function GET(request: NextRequest) {
  return proxyToLaravel(request, "/account/export", { method: "GET", label: "du compte", timeoutMs: 30000 });
}
