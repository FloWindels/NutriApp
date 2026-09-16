import { NextResponse } from "next/server";

const backendBaseUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";

export async function GET(request: Request) {
  try {
    const url = new URL(request.url);
    const q = url.searchParams.get("q") ?? "";
    const barcode = url.searchParams.get("barcode") ?? "";
    const params = new URLSearchParams();

    if (q.trim()) {
      params.set("q", q.trim());
    }

    if (barcode.trim()) {
      params.set("barcode", barcode.trim());
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000);

    const response = await fetch(`${backendBaseUrl}/foods/search?${params.toString()}`, {
      method: "GET",
      headers: {
        Accept: "application/json",
      },
      cache: "no-store",
      signal: controller.signal,
    });

    clearTimeout(timeoutId);

    const text = await response.text();
    const data = text ? JSON.parse(text) : {};

    return NextResponse.json(data, { status: response.status });
  } catch (error) {
    if (error instanceof Error && error.name === "AbortError") {
      return NextResponse.json(
        { message: "La recherche aliments est trop lente. Reessaie dans quelques secondes." },
        { status: 504 },
      );
    }

    return NextResponse.json({ message: "Le serveur aliments est indisponible." }, { status: 503 });
  }
}
