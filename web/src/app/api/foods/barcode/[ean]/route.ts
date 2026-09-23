import { proxyParamRoute } from "@/lib/laravel-proxy";

export const GET = proxyParamRoute<{ ean: string }>(({ ean }) => `/foods/barcode/${ean}`, { label: "aliments", timeoutMs: 20000 });
