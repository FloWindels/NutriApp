import axios from "axios";

export const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api",
  headers: {
    "Content-Type": "application/json",
    Accept: "application/json",
  },
});

type ApiErrorShape = {
  message?: string;
  errors?: Record<string, string[] | string>;
};

function extractApiShape(error: unknown): ApiErrorShape | null {
  if (axios.isAxiosError<ApiErrorShape>(error)) {
    return error.response?.data ?? null;
  }

  if (typeof error === "object" && error !== null && "response" in error) {
    const response = (error as { response?: { data?: unknown } }).response;

    if (response && typeof response.data === "object" && response.data !== null) {
      return response.data as ApiErrorShape;
    }
  }

  return null;
}

export function getApiErrorMessage(error: unknown, fallback: string) {
  const data = extractApiShape(error);

  if (!data) {
    return fallback;
  }

  if (data?.errors) {
    const messages = Object.values(data.errors)
      .flatMap((value) => (Array.isArray(value) ? value : [value]))
      .map((value) => value.toString())
      .filter(Boolean);

    if (messages.length > 0) {
      return messages.join(" ");
    }
  }

  return data?.message || fallback;
}

export function setAuthToken(token: string | null) {
  if (token) {
    api.defaults.headers.common.Authorization = `Bearer ${token}`;
  } else {
    delete api.defaults.headers.common.Authorization;
  }
}