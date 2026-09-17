import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ key: string }> };

export async function PUT(request: NextRequest, { params }: Ctx) {
  const { key } = await params;
  return proxyToLaravel(request, `/notifications/${encodeURIComponent(key)}/read`, { method: "PUT", label: "des notifications" });
}
