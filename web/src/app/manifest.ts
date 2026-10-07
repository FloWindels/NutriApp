import type { MetadataRoute } from "next";

/** Application installable : icône sur l'écran d'accueil, ouverture sans barre de navigateur. */
export default function manifest(): MetadataRoute.Manifest {
  return {
    id: "/",
    name: "Mavi’oh — coach nutrition et sport",
    short_name: "Mavi’oh",
    description: "Ton coach nutrition et sport, au quotidien.",
    lang: "fr",
    start_url: "/dashboard",
    scope: "/",
    display: "standalone",
    orientation: "portrait",
    background_color: "#f3faec",
    theme_color: "#f3faec",
    icons: [
      { src: "/icons/icon-192.png", sizes: "192x192", type: "image/png", purpose: "any" },
      { src: "/icons/icon-512.png", sizes: "512x512", type: "image/png", purpose: "any" },
      { src: "/icons/icon-maskable-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
    ],
  };
}
