import type { NextRequest } from "next/server";
import { proxyQuery, proxyToLaravel } from "@/lib/laravel-proxy";

export async function GET(request: NextRequest) {
  return proxyToLaravel(request, "/weights", { method: "GET", label: "du poids", query: proxyQuery(request) });
}

export async function POST(request: NextRequest) {
  return proxyToLaravel(request, "/weights", { method: "POST", label: "du poids" });
}
