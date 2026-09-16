import { NextResponse } from "next/server";

type RouteContext = {
  params: Promise<{
    barcode: string;
  }>;
};

const backendBaseUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";
const BARCODE_TIMEOUT_MS = 15000;
const CACHE_TTL_MS = 2 * 60 * 1000;

type CachedBarcodeResponse = {
  expiresAt: number;
  status: number;
  payload: unknown;
};

const barcodeCache = new Map<string, CachedBarcodeResponse>();

export async function GET(_: Request, { params }: RouteContext) {
  try {
    const { barcode } = await params;
    const cacheKey = barcode.trim();
    const now = Date.now();
    const cached = barcodeCache.get(cacheKey);
    if (cached && cached.expiresAt > now) {
      return NextResponse.json(cached.payload, { status: cached.status });
    }

    const request = _;
    const authorization = request.headers.get("authorization");
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), BARCODE_TIMEOUT_MS);

    const response = await fetch(`${backendBaseUrl}/foods/barcode/${encodeURIComponent(barcode)}`, {
      method: "GET",
      headers: {
        Accept: "application/json",
        ...(authorization ? { Authorization: authorization } : {}),
      },
      cache: "no-store",
      signal: controller.signal,
    });

    clearTimeout(timeoutId);

    const text = await response.text();
    const data = text ? JSON.parse(text) : {};

    if (response.status === 200) {
      barcodeCache.set(cacheKey, {
        expiresAt: now + CACHE_TTL_MS,
        status: response.status,
        payload: data,
      });
    }

    return NextResponse.json(data, { status: response.status });
  } catch (error) {
    if (error instanceof Error && error.name === "AbortError") {
      return NextResponse.json(
        { message: "Le serveur aliments est trop lent a repondre. Reessaie dans quelques secondes." },
        { status: 504 },
      );
    }

    return NextResponse.json({ message: "Le serveur aliments est indisponible." }, { status: 503 });
  }
}
