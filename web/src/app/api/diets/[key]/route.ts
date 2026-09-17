import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ key: string }> };

export async function GET(request: NextRequest, { params }: Ctx) {
  const { key } = await params;
  return proxyToLaravel(request, `/diets/${encodeURIComponent(key)}`, { method: "GET", label: "des régimes" });
}
