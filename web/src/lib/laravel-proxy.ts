import { NextResponse, type NextRequest } from "next/server";
import { SESSION_COOKIE, SESSION_MARKER, SESSION_MAX_AGE_SECONDS } from "@/lib/session-cookie";

/**
 * Server-side helper shared by every route handler under `src/app/api/**`.
 *
 * Forwards the incoming request to the Laravel API (same path, same method),
 * passing along `Authorization` and `Accept`, and streams the status + JSON body
 * back to the browser unchanged. Network problems become 503, timeouts 504,
 * both with a French `{ message }`.
 */

export type ProxyMethod = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";

export type ProxyQuery =
  | string
  | URLSearchParams
  | Record<string, string | number | boolean | null | undefined>
  | null
  | undefined;

export type ProxyOptions = {
  /** HTTP method sent to Laravel. Defaults to the incoming request's method. */
  method?: ProxyMethod;
  /** Abort after this delay → 504. Default 15 s. */
  timeoutMs?: number;
  /** Short French noun used in error messages (« aliments », « repas »…). */
  label?: string;
  /**
   * Explicit JSON body. When omitted and the method allows a body, the incoming
   * request body is forwarded verbatim.
   */
  body?: unknown;
  /** Forward the incoming `Authorization` header (default true). */
  forwardAuth?: boolean;
  /** Query string appended to the Laravel path (use `proxyQuery(request)` to forward as-is). */
  query?: ProxyQuery;
  /** Taille maximale du corps entrant, en octets → 413 au-delà. 1 Mo par défaut. */
  maxBytes?: number;
  /**
   * Connexion ou inscription : le jeton renvoyé par Laravel part dans le cookie httpOnly, et le
   * corps n'en garde que le témoin `SESSION_MARKER`.
   */
  issueSession?: boolean;
  /** Déconnexion : le cookie de session est effacé quoi que réponde Laravel. */
  endSession?: boolean;
};

const DEFAULT_TIMEOUT_MS = 15_000;
const DEFAULT_MAX_BYTES = 1_000_000;
const SAFE_METHODS = new Set(["GET", "HEAD", "OPTIONS"]);

/**
 * Le cookie de session part avec toute requête vers ce site : une écriture venue d'une autre
 * origine (CSRF) est refusée. Sec-Fetch-Site d'abord, que les navigateurs posent eux-mêmes ;
 * Origin ensuite, comparée à l'hôte servi. Un client sans ces en-têtes n'est pas un navigateur
 * piloté par un site tiers, et n'a de toute façon pas le cookie de quelqu'un d'autre.
 */
function isCrossSiteWrite(request: NextRequest): boolean {
  if (SAFE_METHODS.has(request.method.toUpperCase())) return false;

  const site = request.headers.get("sec-fetch-site");
  if (site && site !== "same-origin") return true;

  const origin = request.headers.get("origin");
  if (!origin) return false;
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host");
  try {
    return new URL(origin).host !== host;
  } catch {
    return true;
  }
}

function sessionCookieOptions() {
  return {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax" as const,
    path: "/",
  };
}

export function laravelBaseUrl(): string {
  const raw =
    process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";
  return raw.replace(/\/+$/, "");
}

/** Returns the incoming request's query string (`?a=1&b=2` or ``) to forward untouched. */
export function proxyQuery(request: NextRequest): string {
  return request.nextUrl.search;
}

function serializeQuery(query: ProxyQuery): string {
  if (!query) return "";
  if (typeof query === "string") {
    if (!query) return "";
    return query.startsWith("?") ? query : `?${query}`;
  }
  const params =
    query instanceof URLSearchParams
      ? query
      : new URLSearchParams(
          Object.entries(query)
            .filter(([, value]) => value !== undefined && value !== null && value !== "")
            .map(([key, value]) => [key, String(value)]),
        );
  const serialized = params.toString();
  return serialized ? `?${serialized}` : "";
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function parseBody(text: string): unknown {
  if (!text) return {};
  try {
    return JSON.parse(text) as unknown;
  } catch {
    return { message: text.slice(0, 500) };
  }
}

export async function proxyToLaravel(
  request: NextRequest,
  path: string,
  options: ProxyOptions = {},
): Promise<NextResponse> {
  const method: ProxyMethod = options.method ?? (request.method.toUpperCase() as ProxyMethod);
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
  const label = options.label ?? "Mavi’oh";
  const forwardAuth = options.forwardAuth ?? true;

  if (isCrossSiteWrite(request)) {
    return NextResponse.json({ message: "Requête refusée." }, { status: 403 });
  }

  const maxBytes = options.maxBytes ?? DEFAULT_MAX_BYTES;
  if (Number(request.headers.get("content-length") ?? 0) > maxBytes) {
    return NextResponse.json({ message: "Le contenu envoyé est trop volumineux." }, { status: 413 });
  }

  const headers: Record<string, string> = { Accept: "application/json" };
  // Le jeton vient du cookie httpOnly, jamais d'un en-tête fourni par la page.
  const token = request.cookies.get(SESSION_COOKIE)?.value;
  if (forwardAuth && token) headers.Authorization = `Bearer ${token}`;
  const acceptLanguage = request.headers.get("accept-language");
  if (acceptLanguage) headers["Accept-Language"] = acceptLanguage;
  // Derrière Cloudflare, l'IP du visiteur : sans elle, Laravel verrait celle de ce serveur et
  // appliquerait ses limites (connexion, codes) à tous les visiteurs ensemble.
  const clientIp = request.headers.get("cf-connecting-ip");
  if (clientIp) headers["CF-Connecting-IP"] = clientIp;

  let body: string | undefined;
  if (method !== "GET") {
    if (options.body !== undefined) {
      body = JSON.stringify(options.body);
      headers["Content-Type"] = "application/json";
    } else {
      const raw = await request.text().catch(() => "");
      // Content-Length peut manquer (envoi fragmenté) : la taille réelle fait foi.
      if (new TextEncoder().encode(raw).length > maxBytes) {
        return NextResponse.json({ message: "Le contenu envoyé est trop volumineux." }, { status: 413 });
      }
      if (raw) {
        body = raw;
        headers["Content-Type"] = request.headers.get("content-type") ?? "application/json";
      }
    }
  }

  const normalizedPath = path.startsWith("/") ? path : `/${path}`;
  const url = `${laravelBaseUrl()}${normalizedPath}${serializeQuery(options.query)}`;

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(url, {
      method,
      headers,
      body,
      cache: "no-store",
      signal: controller.signal,
    });

    let result: NextResponse;
    if (response.status === 204) {
      result = new NextResponse(null, { status: 204 });
    } else {
      const payload = parseBody(await response.text());

      if (options.issueSession && response.ok && isRecord(payload) && typeof payload.token === "string") {
        result = NextResponse.json({ ...payload, token: SESSION_MARKER }, { status: response.status });
        result.cookies.set(SESSION_COOKIE, payload.token, {
          ...sessionCookieOptions(),
          maxAge: SESSION_MAX_AGE_SECONDS,
        });
        return result;
      }

      result = NextResponse.json(payload, { status: response.status });
    }

    // Jeton expiré ou révoqué, ou déconnexion : le cookie ne sert plus à rien.
    if (options.endSession || (response.status === 401 && token)) {
      result.cookies.set(SESSION_COOKIE, "", { ...sessionCookieOptions(), maxAge: 0 });
    }
    return result;
  } catch (error) {
    if (error instanceof Error && error.name === "AbortError") {
      return NextResponse.json(
        {
          message: `Le serveur ${label} met trop de temps à répondre. Réessaie dans quelques secondes.`,
        },
        { status: 504 },
      );
    }
    return NextResponse.json(
      { message: `Le serveur ${label} est indisponible pour le moment.` },
      { status: 503 },
    );
  } finally {
    clearTimeout(timeoutId);
  }
}

/* ------------------------------------------------------------------ */
/* Fabriques de route handlers                                         */
/* ------------------------------------------------------------------ */

/**
 * Les quatre-vingts route handlers du site sont des passe-plats : même import, même corps,
 * seul le chemin Laravel change. Ces deux fabriques rendent le handler directement exportable.
 *
 * Le chemin reste écrit en clair dans chaque fichier, jamais dans une table centrale : une
 * faute de frappe doit rester visible à l'endroit où elle est commise.
 *
 *   export const GET = proxyRoute("/dashboard", { label: "du tableau de bord", forwardQuery: true });
 *   export const PUT = proxyParamRoute(({ id }) => `/foods/${id}`, { label: "aliments" });
 */

export type RouteOptions = Omit<ProxyOptions, "query"> & {
  /** Recopie la chaîne de requête entrante vers Laravel. */
  forwardQuery?: boolean;
};

function withQuery(request: NextRequest, options: RouteOptions): ProxyOptions {
  const { forwardQuery, ...rest } = options;
  return forwardQuery ? { ...rest, query: proxyQuery(request) } : rest;
}

/** Chemin fixe. */
export function proxyRoute(path: string, options: RouteOptions = {}) {
  return (request: NextRequest) => proxyToLaravel(request, path, withQuery(request, options));
}

/**
 * Chemin construit à partir des segments dynamiques, déjà échappés — l'appelant n'a donc
 * jamais à penser à `encodeURIComponent`, oubli facile et silencieux.
 */
export function proxyParamRoute<P extends Record<string, string>>(
  buildPath: (params: P) => string,
  options: RouteOptions = {},
) {
  return async (request: NextRequest, context: { params: Promise<P> }) => {
    const params = await context.params;
    // Un segment comme « .. » survivrait à l'échappement et remonterait d'un cran côté Laravel.
    if (Object.values(params).some((value) => !/^[A-Za-z0-9_-]+$/.test(String(value)))) {
      return NextResponse.json({ message: "Introuvable." }, { status: 404 });
    }
    const escaped = Object.fromEntries(
      Object.entries(params).map(([key, value]) => [key, encodeURIComponent(String(value))]),
    ) as P;

    return proxyToLaravel(request, buildPath(escaped), withQuery(request, options));
  };
}
