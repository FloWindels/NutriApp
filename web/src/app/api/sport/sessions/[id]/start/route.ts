import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ id: string }> };

export async function POST(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/sport/sessions/${encodeURIComponent(id)}/start`, { method: "POST", label: "sport" });
}
