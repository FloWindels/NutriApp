import { NextResponse, type NextRequest } from "next/server";

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
};

const DEFAULT_TIMEOUT_MS = 15_000;

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

  const headers: Record<string, string> = { Accept: "application/json" };
  const authorization = request.headers.get("authorization");
  if (forwardAuth && authorization) headers.Authorization = authorization;
  const acceptLanguage = request.headers.get("accept-language");
  if (acceptLanguage) headers["Accept-Language"] = acceptLanguage;

  let body: string | undefined;
  if (method !== "GET") {
    if (options.body !== undefined) {
      body = JSON.stringify(options.body);
      headers["Content-Type"] = "application/json";
    } else {
      const raw = await request.text().catch(() => "");
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

    if (response.status === 204) {
      return new NextResponse(null, { status: 204 });
    }

    const text = await response.text();
    return NextResponse.json(parseBody(text), { status: response.status });
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
    const escaped = Object.fromEntries(
      Object.entries(params).map(([key, value]) => [key, encodeURIComponent(String(value))]),
    ) as P;

    return proxyToLaravel(request, buildPath(escaped), withQuery(request, options));
  };
}
