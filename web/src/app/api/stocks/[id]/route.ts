import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ id: string }> };

export async function PUT(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/stocks/${encodeURIComponent(id)}`, { method: "PUT", label: "stock" });
}

export async function DELETE(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/stocks/${encodeURIComponent(id)}`, { method: "DELETE", label: "stock" });
}
