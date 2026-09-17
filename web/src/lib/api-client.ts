"use client";

import { messages } from "@/lib/messages";
import { clearSession, getToken } from "@/lib/session";
import type { ApiErrorPayload, ValidationErrors } from "@/lib/types/api";

/**
 * Browser-side fetch wrapper for the same-origin BFF (`/api/**` route handlers).
 *
 * - Adds `Accept`, `Content-Type` and the bearer token from the session.
 * - Parses `{ data, message, errors }` and throws `ApiError` on non-2xx.
 * - 401 on an authenticated path → clears the session → `/login?expired=1`.
 */

export class ApiError extends Error {
  readonly status: number;
  readonly fieldErrors: ValidationErrors;
  readonly payload: unknown;

  constructor(status: number, message: string, fieldErrors: ValidationErrors = {}, payload?: unknown) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.fieldErrors = fieldErrors;
    this.payload = payload;
  }

  /** First message for a given field, if the API returned one. */
  fieldError(field: string): string | undefined {
    return this.fieldErrors[field]?.[0];
  }

  /** All field messages flattened (useful for a banner). */
  get allFieldMessages(): string[] {
    return Object.values(this.fieldErrors).flat().filter(Boolean);
  }

  get isValidation(): boolean {
    return this.status === 422;
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError;
}

/** French message for any thrown value (ApiError, TypeError, unknown). */
export function getErrorMessage(error: unknown, fallback: string = messages.unknown): string {
  if (isApiError(error)) return error.message || fallback;
  if (error instanceof TypeError) return messages.network;
  if (error instanceof Error && error.message) return error.message;
  return fallback;
}

export type QueryValue = string | number | boolean | null | undefined;
export type QueryParams = Record<string, QueryValue>;

export type ApiFetchInit = Omit<RequestInit, "body" | "method"> & {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  /** JSON body (serialised automatically). */
  json?: unknown;
  /** Raw body if you need something other than JSON. */
  body?: BodyInit | null;
  /** Query parameters appended to the path; empty/null values are skipped. */
  query?: QueryParams;
  /** Skip the bearer header (public endpoints). Default false. */
  anonymous?: boolean;
};

const API_PREFIX = "/api";
const PUBLIC_401_PATHS = [
  "/auth/login",
  "/auth/register",
  "/auth/forgot-password",
  "/auth/reset-password",
];

let redirectingToLogin = false;

export function buildQuery(query?: QueryParams): string {
  if (!query) return "";
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === "") continue;
    params.set(key, typeof value === "boolean" ? (value ? "1" : "0") : String(value));
  }
  const serialized = params.toString();
  return serialized ? `?${serialized}` : "";
}

function statusMessage(status: number, payload: ApiErrorPayload | null): string {
  if (payload?.message) return payload.message;
  switch (status) {
    case 401:
      return messages.unauthorized;
    case 403:
      return messages.forbidden;
    case 404:
      return messages.notFound;
    case 429:
      return messages.tooManyRequests;
    case 504:
      return messages.timeout;
    default:
      return status >= 500 ? messages.server : messages.unknown;
  }
}

function handleExpiredSession() {
  if (typeof window === "undefined" || redirectingToLogin) return;
  redirectingToLogin = true;
  clearSession();
  if (!window.location.pathname.startsWith("/login")) {
    window.location.assign("/login?expired=1");
  } else {
    redirectingToLogin = false;
  }
}

/**
 * Fetch `path` (relative to `/api`, e.g. `/meals?date=2026-09-16`) and return the parsed JSON.
 * The generic `T` is the *whole* envelope (`{ data, message }`), so callers pick `.data`.
 */
export async function apiFetch<T = unknown>(path: string, init: ApiFetchInit = {}): Promise<T> {
  const { json, query, anonymous = false, headers: extraHeaders, ...rest } = init;
  const headers = new Headers(extraHeaders);
  headers.set("Accept", "application/json");

  let body: BodyInit | null | undefined = rest.body;
  if (json !== undefined) {
    body = JSON.stringify(json);
    headers.set("Content-Type", "application/json");
  }

  const token = anonymous ? null : getToken();
  if (token && !headers.has("Authorization")) headers.set("Authorization", `Bearer ${token}`);

  const normalizedPath = path.startsWith("/") ? path : `/${path}`;
  const url = `${API_PREFIX}${normalizedPath}${buildQuery(query)}`;

  let response: Response;
  try {
    response = await fetch(url, {
      ...rest,
      method: rest.method ?? (json !== undefined || body ? "POST" : "GET"),
      headers,
      body,
      cache: "no-store",
    });
  } catch {
    throw new ApiError(0, messages.network);
  }

  const text = await response.text();
  let payload: unknown = null;
  if (text) {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = { message: messages.invalidResponse };
    }
  }

  if (response.ok) {
    return (payload ?? {}) as T;
  }

  const errorPayload = (payload && typeof payload === "object" ? payload : null) as ApiErrorPayload | null;
  const fieldErrors: ValidationErrors = {};
  if (errorPayload?.errors && typeof errorPayload.errors === "object") {
    for (const [field, value] of Object.entries(errorPayload.errors)) {
      fieldErrors[field] = Array.isArray(value) ? value.map(String) : [String(value)];
    }
  }

  const isPublicAuthPath = PUBLIC_401_PATHS.some((publicPath) =>
    normalizedPath.startsWith(publicPath),
  );
  if (response.status === 401 && !isPublicAuthPath && token) {
    handleExpiredSession();
  }

  throw new ApiError(
    response.status,
    statusMessage(response.status, errorPayload),
    fieldErrors,
    payload,
  );
}

/* Typed shorthands */
export const apiGet = <T>(path: string, query?: QueryParams, init?: ApiFetchInit) =>
  apiFetch<T>(path, { ...init, method: "GET", query });
export const apiPost = <T>(path: string, json?: unknown, init?: ApiFetchInit) =>
  apiFetch<T>(path, { ...init, method: "POST", json: json ?? {} });
export const apiPut = <T>(path: string, json?: unknown, init?: ApiFetchInit) =>
  apiFetch<T>(path, { ...init, method: "PUT", json: json ?? {} });
export const apiDelete = <T>(path: string, json?: unknown, init?: ApiFetchInit) =>
  apiFetch<T>(path, { ...init, method: "DELETE", ...(json !== undefined ? { json } : {}) });
