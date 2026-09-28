import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

/** La rédaction d'une recette par un modèle local dépasse le délai par défaut du relais. */
export async function POST(request: NextRequest) {
  return proxyToLaravel(request, "/recipes/generate", {
    method: "POST",
    label: "recettes",
    timeoutMs: 120000,
  });
}
