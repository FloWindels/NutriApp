import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

export async function PUT(request: NextRequest) {
  return proxyToLaravel(request, "/household/members/me", { method: "PUT", label: "du foyer" });
}
