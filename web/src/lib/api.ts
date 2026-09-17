/**
 * @deprecated Legacy helper kept for the pages not yet migrated to `@/lib/api-client`.
 * New code must use `apiFetch` + `ApiError` + `getErrorMessage` from `@/lib/api-client`.
 */
import { getErrorMessage, isApiError } from "@/lib/api-client";

type ApiErrorShape = {
  message?: string;
  errors?: Record<string, string[] | string>;
};

function extractApiShape(error: unknown): ApiErrorShape | null {
  if (typeof error === "object" && error !== null && "response" in error) {
    const response = (error as { response?: { data?: unknown } }).response;
    if (response && typeof response.data === "object" && response.data !== null) {
      return response.data as ApiErrorShape;
    }
  }
  return null;
}

/** Accepts an `ApiError`, a legacy `{ response: { data } }` shape or anything else. */
export function getApiErrorMessage(error: unknown, fallback: string): string {
  if (isApiError(error)) {
    const fieldMessages = error.allFieldMessages;
    return fieldMessages.length > 0 ? fieldMessages.join(" ") : error.message || fallback;
  }

  const data = extractApiShape(error);
  if (!data) {
    return getErrorMessage(error, fallback);
  }

  if (data.errors) {
    const messages = Object.values(data.errors)
      .flatMap((value) => (Array.isArray(value) ? value : [value]))
      .map((value) => String(value))
      .filter(Boolean);
    if (messages.length > 0) return messages.join(" ");
  }

  return data.message || fallback;
}
