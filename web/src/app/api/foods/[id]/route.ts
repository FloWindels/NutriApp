import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ id: string }> };

export async function GET(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/foods/${encodeURIComponent(id)}`, { method: "GET", label: "aliments" });
}

export async function PUT(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/foods/${encodeURIComponent(id)}`, { method: "PUT", label: "aliments" });
}
