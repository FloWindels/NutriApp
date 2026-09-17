import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ date: string }> };

export async function DELETE(request: NextRequest, { params }: Ctx) {
  const { date } = await params;
  return proxyToLaravel(request, `/weights/${encodeURIComponent(date)}`, { method: "DELETE", label: "du poids" });
}
