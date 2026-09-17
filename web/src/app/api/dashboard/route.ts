import type { NextRequest } from "next/server";
import { proxyQuery, proxyToLaravel } from "@/lib/laravel-proxy";

export async function GET(request: NextRequest) {
  return proxyToLaravel(request, "/dashboard", { method: "GET", label: "du tableau de bord", query: proxyQuery(request) });
}
