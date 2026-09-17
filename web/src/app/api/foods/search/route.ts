import type { NextRequest } from "next/server";
import { proxyQuery, proxyToLaravel } from "@/lib/laravel-proxy";

export async function GET(request: NextRequest) {
  return proxyToLaravel(request, "/foods/search", { method: "GET", label: "aliments", timeoutMs: 20000, query: proxyQuery(request) });
}
