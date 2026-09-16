import { NextResponse } from "next/server";

const backendBaseUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";

export async function POST(request: Request) {
  try {
    const authorization = request.headers.get("authorization");
    const body = await request.json();
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 15000);

    const response = await fetch(`${backendBaseUrl}/stocks/items`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        ...(authorization ? { Authorization: authorization } : {}),
      },
      body: JSON.stringify(body),
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
        { message: "Le serveur frigo est trop lent a repondre. Reessaie dans quelques secondes." },
        { status: 504 },
      );
    }

    return NextResponse.json({ message: "Le serveur frigo est indisponible." }, { status: 503 });
  }
}
