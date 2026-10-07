import type { Metadata, Viewport } from "next";
import { Geist_Mono, Roboto } from "next/font/google";
import "./globals.css";
import { Providers } from "./providers";

// Roboto, comme l'application mobile : le site et l'app se lisent de la même façon.
const roboto = Roboto({
  variable: "--font-roboto",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: {
    default: "Mavi’oh",
    template: "%s · Mavi’oh",
  },
  description: "Mavi’oh — ton coach nutrition et sport, au quotidien.",
  applicationName: "Mavi’oh",
  icons: {
    icon: "/favicon.png",
    shortcut: "/favicon.png",
    // Icône de l'écran d'accueil de l'iPhone : fond opaque, sans quoi iOS remplit de noir.
    apple: [{ url: "/apple-icon.png", sizes: "180x180", type: "image/png" }],
  },
  // Ajoutée à l'écran d'accueil, l'application s'ouvre en plein écran, sans Safari autour.
  appleWebApp: {
    capable: true,
    title: "Mavi’oh",
    statusBarStyle: "default",
  },
  formatDetection: { telephone: false },
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  // Le contenu passe sous l'encoche ; les barres de l'application s'en écartent (safe-area).
  viewportFit: "cover",
  themeColor: "#f3faec",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="fr"
      suppressHydrationWarning
      className={`${roboto.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body suppressHydrationWarning className="min-h-full flex flex-col">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
