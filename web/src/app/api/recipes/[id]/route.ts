import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ id: string }> };

export async function GET(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/recipes/${encodeURIComponent(id)}`, { method: "GET", label: "recettes" });
}

export async function PUT(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/recipes/${encodeURIComponent(id)}`, { method: "PUT", label: "recettes", timeoutMs: 30000 });
}

export async function DELETE(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/recipes/${encodeURIComponent(id)}`, { method: "DELETE", label: "recettes" });
}
