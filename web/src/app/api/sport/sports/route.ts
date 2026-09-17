import type { NextRequest } from "next/server";
import { proxyQuery, proxyToLaravel } from "@/lib/laravel-proxy";

export async function GET(request: NextRequest) {
  return proxyToLaravel(request, "/sport/sports", { method: "GET", label: "sport", query: proxyQuery(request) });
}

export async function POST(request: NextRequest) {
  return proxyToLaravel(request, "/sport/sports", { method: "POST", label: "sport" });
}
