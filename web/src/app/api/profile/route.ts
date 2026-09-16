import { NextResponse } from "next/server";

const backendBaseUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";

export async function GET(request: Request) {
  try {
    const authorization = request.headers.get("authorization");

    const response = await fetch(`${backendBaseUrl}/profile`, {
      method: "GET",
      headers: {
        Accept: "application/json",
        ...(authorization ? { Authorization: authorization } : {}),
      },
      cache: "no-store",
    });

    const text = await response.text();
    const data = text ? JSON.parse(text) : {};

    return NextResponse.json(data, { status: response.status });
  } catch {
    return NextResponse.json(
      { message: "Le serveur de profil est indisponible." },
      { status: 503 },
    );
  }
}

export async function PUT(request: Request) {
  try {
    const authorization = request.headers.get("authorization");
    const body = await request.json();

    const response = await fetch(`${backendBaseUrl}/profile`, {
      method: "PUT",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        ...(authorization ? { Authorization: authorization } : {}),
      },
      body: JSON.stringify(body),
      cache: "no-store",
    });

    const text = await response.text();
    const data = text ? JSON.parse(text) : {};

    return NextResponse.json(data, { status: response.status });
  } catch {
    return NextResponse.json(
      { message: "Le serveur de profil est indisponible." },
      { status: 503 },
    );
  }
}
