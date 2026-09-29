"use client";

import Link from "next/link";
import type { ReactNode } from "react";
import { Card } from "@/components/ui/card";
import { useOffre } from "@/hooks/use-offre";
import { cn } from "@/lib/cn";
import { argumentaire } from "@/lib/offres-capacites";

/**
 * Ce que voit un compte gratuit sur une section verrouillée.
 *
 * On montre ce que la fonctionnalité apporte plutôt qu'un simple refus : quelqu'un ne paie
 * que ce dont il a compris l'intérêt.
 */
export function OffreRequise({
  titre,
  argument,
  offre = "Complet",
  compact = false,
  className,
}: {
  titre: string;
  argument: string;
  offre?: string;
  /** Version resserrée, pour une sous-section ou l'intérieur d'une fenêtre. */
  compact?: boolean;
  className?: string;
}) {
  return (
    <Card padding={compact ? "md" : "lg"} className={cn("mx-auto max-w-xl text-center", className)}>
      <p className="text-xs font-semibold uppercase tracking-[0.14em] text-emerald-800">
        Offre {offre}
      </p>
      <h2 className={cn("mt-2 font-semibold text-slate-950", compact ? "text-base" : "text-xl")}>
        {titre}
      </h2>
      <p className="mt-2 text-sm leading-6 text-slate-600">{argument}</p>

      <div className={cn("flex flex-wrap justify-center gap-2", compact ? "mt-4" : "mt-5")}>
        <Link
          href="/offres"
          className="inline-flex h-11 items-center justify-center rounded-2xl border border-emerald-700 bg-emerald-700 px-4 text-sm font-medium text-white transition hover:bg-emerald-800"
        >
          Voir les offres
        </Link>
        <Link
          href="/dashboard/settings"
          className="inline-flex h-11 items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
        >
          J’ai un code d’invitation
        </Link>
      </div>
    </Card>
  );
}

/**
 * L'invitation écrite pour une capacité précise, telle que le serveur la nomme.
 *
 * Passer par ce composant plutôt que par `OffreRequise` évite qu'un écran réécrive
 * l'argumentaire dans son coin, avec le ton approximatif qui va avec.
 */
export function OffreRequisePourCapacite({
  capacite,
  compact = false,
  className,
}: {
  capacite: string | null | undefined;
  compact?: boolean;
  className?: string;
}) {
  const { titre, argument, offre } = argumentaire(capacite);

  return (
    <OffreRequise
      titre={titre}
      argument={argument}
      offre={offre}
      compact={compact}
      className={className}
    />
  );
}

/**
 * Un module entier réservé aux offres payantes.
 *
 * Tant que `/me` n'a pas répondu, `peut()` accorde le bénéfice du doute : personne qui a payé
 * ne voit passer l'invitation. Les enfants ne sont pas montés quand c'est verrouillé, donc
 * aucune requête vouée au 402 n'est envoyée et aucun formulaire inutile ne s'affiche.
 */
export function ModulePayant({
  capacite,
  children,
}: {
  capacite: string;
  children: ReactNode;
}) {
  const { peut } = useOffre();

  if (peut(capacite)) return <>{children}</>;

  return <OffreRequisePourCapacite capacite={capacite} />;
}
