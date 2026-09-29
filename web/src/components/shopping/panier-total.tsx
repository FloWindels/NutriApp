"use client";

import { Card } from "@/components/ui/card";
import { formatLongDate, formatPrix } from "@/lib/format";
import type { MagasinBref, PanierEstimation } from "@/lib/types/api";

/**
 * Le total du panier.
 *
 * Tout ici est écrit pour une seule situation : celle où quelqu'un découvre vingt euros d'écart
 * à la caisse. Le mot « estimé » est dans le titre, la date des prix est sous le montant, et
 * l'avertissement du serveur est affiché tel quel, sans être résumé. Personne ne doit pouvoir
 * dire qu'on lui avait promis un prix.
 */
export function PanierTotal({
  estimation,
  magasin,
}: {
  estimation: PanierEstimation;
  magasin: MagasinBref | null;
}) {
  const total = estimation.total_estime;

  return (
    <Card padding="md" className="space-y-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-semibold text-slate-900">
            Total estimé{magasin ? ` · ${magasin.enseigne_libelle}` : ""}
          </p>
          <p className="mt-0.5 text-xs text-slate-500">
            {total === null
              ? "Aucun prix n’est calculé."
              : `${estimation.lignes_estimees} ligne${estimation.lignes_estimees > 1 ? "s" : ""} estimée${
                  estimation.lignes_estimees > 1 ? "s" : ""
                }${
                  estimation.lignes_sans_prix > 0
                    ? ` · ${estimation.lignes_sans_prix} sans prix connu`
                    : ""
                }`}
          </p>
        </div>

        {total === null ? null : (
          <p className="text-2xl font-semibold tabular-nums text-slate-950">
            <span className="text-slate-400" aria-hidden="true">
              ≈{" "}
            </span>
            <span className="sr-only">Estimé à </span>
            {formatPrix(total, estimation.devise)}
          </p>
        )}
      </div>

      {estimation.economie_promotions_estimee > 0 ? (
        <p className="text-sm text-emerald-800">
          Dont {formatPrix(estimation.economie_promotions_estimee, estimation.devise)} d’économie
          estimée grâce aux promotions retenues.
        </p>
      ) : null}

      <p className="rounded-2xl bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">
        {estimation.avertissement}
        {estimation.prix_les_plus_anciens
          ? ` Le plus ancien prix retenu date du ${formatLongDate(estimation.prix_les_plus_anciens)}.`
          : ""}
      </p>
    </Card>
  );
}

export default PanierTotal;
