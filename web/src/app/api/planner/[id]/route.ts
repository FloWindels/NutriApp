import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ id: string }> };

export async function PUT(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/planner/${encodeURIComponent(id)}`, { method: "PUT", label: "du planificateur" });
}

export async function DELETE(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/planner/${encodeURIComponent(id)}`, { method: "DELETE", label: "du planificateur" });
}
