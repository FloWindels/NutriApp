import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

type Ctx = { params: Promise<{ ean: string }> };

export async function GET(request: NextRequest, { params }: Ctx) {
  const { ean } = await params;
  return proxyToLaravel(request, `/foods/barcode/${encodeURIComponent(ean)}`, { method: "GET", label: "aliments", timeoutMs: 20000 });
}
