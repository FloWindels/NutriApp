import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ id: string; itemId: string }> };

export async function PUT(request: NextRequest, { params }: Ctx) {
  const { id, itemId } = await params;
  return proxyToLaravel(request, `/meals/${encodeURIComponent(id)}/items/${encodeURIComponent(itemId)}`, { method: "PUT", label: "repas" });
}

export async function DELETE(request: NextRequest, { params }: Ctx) {
  const { id, itemId } = await params;
  return proxyToLaravel(request, `/meals/${encodeURIComponent(id)}/items/${encodeURIComponent(itemId)}`, { method: "DELETE", label: "repas" });
}
