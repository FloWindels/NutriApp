import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

export async function DELETE(request: NextRequest) {
  return proxyToLaravel(request, "/shopping-list/checked", { method: "DELETE", label: "de la liste de courses" });
}
