import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

/** Une analyse vision dépasse largement le délai par défaut de 15 s du relais. */
export async function POST(request: NextRequest) {
  return proxyToLaravel(request, "/meals/analyze-photo", {
    method: "POST",
    label: "de la photo",
    timeoutMs: 120000,
  });
}
