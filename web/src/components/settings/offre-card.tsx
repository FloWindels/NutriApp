"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { apiGet, apiPost, getErrorMessage } from "@/lib/api-client";
import { queryKeys } from "@/lib/query-keys";
import type { Me } from "@/lib/types/api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Field } from "@/components/ui/field";
import { useToast } from "@/components/ui/toast";

/**
 * Offre courante et saisie d'un code d'invitation.
 *
 * Aucun paiement n'existe : le seul moyen d'ouvrir l'application entière est un code remis par
 * l'éditeur, et c'est dit sans détour plutôt que caché derrière un bouton inerte.
 */
export function OffreCard() {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [code, setCode] = useState("");
  const [erreur, setErreur] = useState<string | null>(null);

  const me = useQuery({ queryKey: queryKeys.me, queryFn: () => apiGet<Me>("/auth/me") });

  const echanger = useMutation({
    mutationFn: (saisie: string) =>
      apiPost<{ data: { offre_libelle: string } }>("/account/code", { code: saisie }),
    onSuccess: (reponse) => {
      setCode("");
      setErreur(null);
      queryClient.invalidateQueries();
      toast.success("Code accepté.", `Ton offre est désormais « ${reponse.data.offre_libelle} ».`);
    },
    onError: (error) => setErreur(getErrorMessage(error, "Ce code n’a pas été accepté.")),
  });

  const offre = me.data?.offre;

  return (
    <Card padding="md" className="space-y-3">
      <div>
        <p className="text-sm font-semibold text-slate-900">Ton offre</p>
        <p className="mt-1 text-sm text-slate-600">
          {offre ? (
            <>
              Tu es sur l’offre <strong>{offre.libelle}</strong>
              {offre.expire_le ? ` jusqu’au ${offre.expire_le.slice(0, 10)}` : ""}.{" "}
              {offre.nom === "gratuit" ? (
                <>
                  Le stock, le foyer, le coach et l’IA font partie des{" "}
                  <Link href="/offres" className="text-emerald-800 hover:underline">
                    offres payantes
                  </Link>
                  .
                </>
              ) : (
                "Toutes les fonctionnalités te sont ouvertes."
              )}
            </>
          ) : (
            "Chargement…"
          )}
        </p>
      </div>

      <form
        className="flex flex-wrap items-end gap-2"
        onSubmit={(event) => {
          event.preventDefault();
          setErreur(null);
          const saisie = code.trim();
          if (saisie.length < 4) {
            setErreur("Saisis le code que tu as reçu.");
            return;
          }
          echanger.mutate(saisie);
        }}
      >
        <div className="min-w-[12rem] flex-1">
          <Field
            label="J’ai un code d’invitation"
            value={code}
            onChange={(event) => setCode(event.target.value.toUpperCase())}
            placeholder="ABCD2345"
            autoComplete="off"
            spellCheck={false}
          />
        </div>
        <Button type="submit" variant="secondary" loading={echanger.isPending}>
          Valider
        </Button>
      </form>

      {erreur ? <Banner tone="error">{erreur}</Banner> : null}
    </Card>
  );
}
