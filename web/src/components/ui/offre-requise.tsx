"use client";

import Link from "next/link";
import { Card } from "@/components/ui/card";

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
}: {
  titre: string;
  argument: string;
  offre?: string;
}) {
  return (
    <Card padding="lg" className="mx-auto max-w-xl text-center">
      <p className="text-xs font-semibold uppercase tracking-[0.14em] text-emerald-800">
        Offre {offre}
      </p>
      <h2 className="mt-2 text-xl font-semibold text-slate-950">{titre}</h2>
      <p className="mt-2 text-sm leading-6 text-slate-600">{argument}</p>

      <div className="mt-5 flex flex-wrap justify-center gap-2">
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
