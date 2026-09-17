import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ id: string }> };

export async function POST(request: NextRequest, { params }: Ctx) {
  const { id } = await params;
  return proxyToLaravel(request, `/planner/${encodeURIComponent(id)}/log`, { method: "POST", label: "du planificateur" });
}
