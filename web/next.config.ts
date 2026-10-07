import type { NextConfig } from "next";

/**
 * Extra origins allowed to reach the dev server (LAN testing from a phone, etc.).
 * Comma-separated list in `NEXT_ALLOWED_DEV_ORIGINS`, e.g. `192.168.1.20,*.local`.
 */
const allowedDevOrigins = (process.env.NEXT_ALLOWED_DEV_ORIGINS ?? "")
  .split(",")
  .map((origin) => origin.trim())
  .filter(Boolean);

/**
 * En-têtes de sécurité de toutes les pages. Les scripts en ligne restent permis : Next en émet
 * pour l'hydratation, et aucun contenu utilisateur ni réponse d'IA n'est rendu en HTML brut.
 * Les images externes (photos de recettes et d'aliments) sont admises en HTTPS seulement.
 */
const securityHeaders = [
  {
    key: "Content-Security-Policy",
    value: [
      "default-src 'self'",
      "script-src 'self' 'unsafe-inline'",
      "style-src 'self' 'unsafe-inline'",
      "img-src 'self' data: blob: https:",
      "font-src 'self'",
      "connect-src 'self'",
      "media-src 'self' blob:",
      "object-src 'none'",
      "base-uri 'self'",
      "form-action 'self'",
      "frame-ancestors 'none'",
      "upgrade-insecure-requests",
    ].join("; "),
  },
  { key: "Strict-Transport-Security", value: "max-age=63072000; includeSubDomains" },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  // La caméra sert à photographier une assiette ou un code-barres, depuis ce site seulement.
  { key: "Permissions-Policy", value: "camera=(self), microphone=(), geolocation=(), payment=(), usb=()" },
  { key: "Cross-Origin-Opener-Policy", value: "same-origin" },
];

const nextConfig: NextConfig = {
  // Serveur autonome (node server.js) : l'image Docker n'embarque que le nécessaire.
  output: "standalone",
  poweredByHeader: false,
  async headers() {
    return [
      { source: "/:path*", headers: securityHeaders },
      // Le service worker est relu à chaque visite : une mise à jour arrive sans attendre.
      { source: "/sw.js", headers: [{ key: "Cache-Control", value: "no-cache" }] },
      // Réponses de l'API relais : jamais mises en cache, nulle part.
      { source: "/api/:path*", headers: [{ key: "Cache-Control", value: "no-store" }] },
    ];
  },
  ...(allowedDevOrigins.length > 0 ? { allowedDevOrigins } : {}),
};

export default nextConfig;
