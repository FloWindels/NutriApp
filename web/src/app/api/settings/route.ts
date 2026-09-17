import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

export async function GET(request: NextRequest) {
  return proxyToLaravel(request, "/settings", { method: "GET", label: "des paramètres" });
}

export async function PUT(request: NextRequest) {
  return proxyToLaravel(request, "/settings", { method: "PUT", label: "des paramètres" });
}
