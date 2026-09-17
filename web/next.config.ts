import type { NextConfig } from "next";

/**
 * Extra origins allowed to reach the dev server (LAN testing from a phone, etc.).
 * Comma-separated list in `NEXT_ALLOWED_DEV_ORIGINS`, e.g. `192.168.1.20,*.local`.
 */
const allowedDevOrigins = (process.env.NEXT_ALLOWED_DEV_ORIGINS ?? "")
  .split(",")
  .map((origin) => origin.trim())
  .filter(Boolean);

const nextConfig: NextConfig = {
  ...(allowedDevOrigins.length > 0 ? { allowedDevOrigins } : {}),
};

export default nextConfig;
