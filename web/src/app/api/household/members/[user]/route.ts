import { proxyParamRoute } from "@/lib/laravel-proxy";

export const DELETE = proxyParamRoute<{ user: string }>(({ user }) => `/household/members/${user}`, { label: "du foyer" });
