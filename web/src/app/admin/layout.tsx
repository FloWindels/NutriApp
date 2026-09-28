import type { Metadata } from "next";
import type { ReactNode } from "react";
import { Providers } from "@/app/providers";

/**
 * L'espace d'administration a sa propre racine : ni la barre latérale du tableau de bord, ni
 * son menu, où une entrée « administration » serait visible par tout le monde.
 */
export const metadata: Metadata = {
  title: "Administration",
  // Cette zone n'a rien à faire dans un moteur de recherche.
  robots: { index: false, follow: false },
};

export default function AdminLayout({ children }: { children: ReactNode }) {
  return <Providers>{children}</Providers>;
}
