import { proxyParamRoute } from "@/lib/laravel-proxy";

export const GET = proxyParamRoute<{ key: string }>(({ key }) => `/diets/${key}`, { label: "des régimes" });
