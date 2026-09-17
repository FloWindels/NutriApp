import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ id: string }> };

export async function POST(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/foods/${encodeURIComponent(id)}/favorite`, { method: "POST", label: "aliments" });
}

export async function DELETE(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/foods/${encodeURIComponent(id)}/favorite`, { method: "DELETE", label: "aliments" });
}
