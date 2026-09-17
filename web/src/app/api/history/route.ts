import type { NextRequest } from "next/server";
import { proxyQuery, proxyToLaravel } from "@/lib/laravel-proxy";

export async function GET(request: NextRequest) {
  return proxyToLaravel(request, "/history", { method: "GET", label: "de l’historique", query: proxyQuery(request) });
}
