import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

export async function PUT(request: NextRequest) {
  return proxyToLaravel(request, "/account", { method: "PUT", label: "du compte" });
}

export async function DELETE(request: NextRequest) {
  return proxyToLaravel(request, "/account", { method: "DELETE", label: "du compte" });
}
