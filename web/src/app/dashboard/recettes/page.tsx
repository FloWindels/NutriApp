"use client";

import { useSearchParams } from "next/navigation";
import { Suspense } from "react";
import RecipesPage from "@/components/recipes/recipes-page";
import { SectionHeader } from "@/components/ui/section-header";
import { SkeletonCard } from "@/components/ui/skeleton";

/** « Recettes » (§5 et §17) — la liste, et le générateur qu'un autre écran peut amorcer. */
export default function RecettesRoute() {
  return (
    <Suspense fallback={<RecettesFallback />}>
      <RecettesContenu />
    </Suspense>
  );
}

function RecettesContenu() {
  const params = useSearchParams();

  // L'encart des promotions de la liste de courses arrive ici avec sa phrase toute faite.
  // Sans elle, quelqu'un qui vient de lire « pâtes, poulet, courgettes » devrait les retaper
  // de mémoire — et en oublierait la moitié avant d'avoir trouvé le bouton du générateur.
  return <RecipesPage demandeInitiale={params.get("demande") ?? ""} />;
}

function RecettesFallback() {
  return (
    <div className="space-y-6">
      <SectionHeader level="page" tone="emerald" eyebrow="Nutrition" title="Recettes" />
      <SkeletonCard />
    </div>
  );
}
