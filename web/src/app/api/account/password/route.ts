import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

export async function PUT(request: NextRequest) {
  return proxyToLaravel(request, "/account/password", { method: "PUT", label: "du compte" });
}
