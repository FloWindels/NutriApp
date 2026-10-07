import { NextResponse, type NextRequest } from "next/server";
import { laravelBaseUrl } from "@/lib/laravel-proxy";
import { SESSION_COOKIE } from "@/lib/session-cookie";

/**
 * Garde côté serveur, avant tout rendu : sans session, aucune page de l'application n'est
 * servie — ni son squelette, ni son code.
 *
 *   /dashboard/**  → redirection vers /login
 *   /admin/**      → 404 pour quiconque n'est pas administrateur, comme si l'espace
 *                    n'existait pas (l'API répond déjà 404 aux non-administrateurs)
 */
export async function proxy(request: NextRequest) {
  const token = request.cookies.get(SESSION_COOKIE)?.value;
  const { pathname } = request.nextUrl;

  if (pathname.startsWith("/admin")) {
    if (!token || !(await estAdministrateur(token))) {
      return new NextResponse("Introuvable.", { status: 404 });
    }
    return NextResponse.next();
  }

  if (!token) {
    const login = new URL("/login", request.url);
    return NextResponse.redirect(login);
  }
  return NextResponse.next();
}

async function estAdministrateur(token: string): Promise<boolean> {
  try {
    const response = await fetch(`${laravelBaseUrl()}/admin/stats`, {
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      cache: "no-store",
      signal: AbortSignal.timeout(5000),
    });
    return response.ok;
  } catch {
    return false;
  }
}

export const config = {
  matcher: ["/dashboard/:path*", "/admin/:path*"],
};
