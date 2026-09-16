import { NextResponse } from "next/server";

const backendBaseUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";

export async function GET(request: Request) {
  try {
    const authorization = request.headers.get("authorization");
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 15000);

    const response = await fetch(`${backendBaseUrl}/me`, {
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

    return NextResponse.json(data, { status: response.status });
  } catch (error) {
    if (error instanceof Error && error.name === "AbortError") {
      return NextResponse.json(
        { message: "Verification de session trop lente, reessaie dans quelques secondes." },
        { status: 504 },
      );
    }

    return NextResponse.json(
      { message: "Le serveur d'authentification est indisponible." },
      { status: 503 },
    );
  }
}
