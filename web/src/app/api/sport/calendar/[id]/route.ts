import type { NextRequest } from "next/server";
import { proxyQuery, proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ id: string }> };

export async function PUT(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/sport/calendar/${encodeURIComponent(id)}`, { method: "PUT", label: "sport" });
}

export async function DELETE(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/sport/calendar/${encodeURIComponent(id)}`, { method: "DELETE", label: "sport", query: proxyQuery(request) });
}
