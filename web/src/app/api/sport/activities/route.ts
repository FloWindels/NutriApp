import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

export async function POST(request: NextRequest) {
  return proxyToLaravel(request, "/sport/activities", { method: "POST", label: "sport" });
}
