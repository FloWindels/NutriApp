import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

export async function GET(request: NextRequest) {
  return proxyToLaravel(request, "/profile", { method: "GET", label: "du profil" });
}

export async function PUT(request: NextRequest) {
  return proxyToLaravel(request, "/profile", { method: "PUT", label: "du profil" });
}
