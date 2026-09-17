import type { NextRequest } from "next/server";
import { proxyQuery, proxyToLaravel } from "@/lib/laravel-proxy";

export async function GET(request: NextRequest) {
  return proxyToLaravel(request, "/planner", { method: "GET", label: "du planificateur", query: proxyQuery(request) });
}

export async function POST(request: NextRequest) {
  return proxyToLaravel(request, "/planner", { method: "POST", label: "du planificateur" });
}
