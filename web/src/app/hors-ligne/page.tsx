import type { Metadata } from "next";

export const metadata: Metadata = { title: "Hors ligne" };

/** Affichée par le service worker quand le réseau ne répond pas. */
export default function HorsLignePage() {
  return (
    <main className="grid min-h-screen place-items-center bg-[#f3faec] px-6 text-center text-[#0b3f2f]">
      <div className="max-w-sm space-y-3">
        <h1 className="text-xl font-semibold">Pas de connexion</h1>
        <p className="text-sm text-slate-600">
          Mavi’oh a besoin d’Internet pour afficher tes données. Vérifie ta connexion, puis
          réessaie.
        </p>
        {/* Rechargement complet voulu : une navigation côté client échouerait sans réseau. */}
        {/* eslint-disable-next-line @next/next/no-html-link-for-pages */}
        <a
          href="/dashboard"
          className="inline-flex h-12 items-center justify-center rounded-2xl bg-emerald-700 px-6 text-sm font-semibold text-white"
        >
          Réessayer
        </a>
      </div>
    </main>
  );
}
