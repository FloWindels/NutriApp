import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

export async function POST(request: NextRequest) {
  return proxyToLaravel(request, "/shopping-list/generate", { method: "POST", label: "de la liste de courses", timeoutMs: 30000 });
}
