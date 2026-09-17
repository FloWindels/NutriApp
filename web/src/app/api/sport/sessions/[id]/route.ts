import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ id: string }> };

export async function GET(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/sport/sessions/${encodeURIComponent(id)}`, { method: "GET", label: "sport" });
}

export async function PUT(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/sport/sessions/${encodeURIComponent(id)}`, { method: "PUT", label: "sport" });
}

export async function DELETE(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/sport/sessions/${encodeURIComponent(id)}`, { method: "DELETE", label: "sport" });
}
