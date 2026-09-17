import type { NextRequest } from "next/server";
import { proxyToLaravel } from "@/lib/laravel-proxy";

export async function GET(request: NextRequest) {
  return proxyToLaravel(request, "/household", { method: "GET", label: "du foyer" });
}

export async function POST(request: NextRequest) {
  return proxyToLaravel(request, "/household", { method: "POST", label: "du foyer" });
}

export async function PUT(request: NextRequest) {
  return proxyToLaravel(request, "/household", { method: "PUT", label: "du foyer" });
}

export async function DELETE(request: NextRequest) {
  return proxyToLaravel(request, "/household", { method: "DELETE", label: "du foyer" });
}
