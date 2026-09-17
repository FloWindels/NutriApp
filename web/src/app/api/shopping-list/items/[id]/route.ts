import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ id: string }> };

export async function PUT(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/shopping-list/items/${encodeURIComponent(id)}`, { method: "PUT", label: "de la liste de courses" });
}

export async function DELETE(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/shopping-list/items/${encodeURIComponent(id)}`, { method: "DELETE", label: "de la liste de courses" });
}
