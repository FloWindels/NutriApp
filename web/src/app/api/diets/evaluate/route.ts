import type { NextRequest } from "next/server";
import { proxyQuery, proxyToLaravel } from "@/lib/laravel-proxy";

export async function GET(request: NextRequest) {
  return proxyToLaravel(request, "/diets/evaluate", { method: "GET", label: "des régimes", query: proxyQuery(request) });
}
