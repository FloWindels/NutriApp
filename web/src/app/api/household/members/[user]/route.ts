import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ user: string }> };

export async function DELETE(request: NextRequest, { params }: Ctx) {
  const { user } = await params;
  return proxyToLaravel(request, `/household/members/${encodeURIComponent(user)}`, { method: "DELETE", label: "du foyer" });
}
