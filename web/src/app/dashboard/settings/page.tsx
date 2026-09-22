"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { SwitchRow } from "@/components/settings/switch";
import { AccountModals } from "@/components/settings/account-modals";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ErrorState } from "@/components/ui/error-state";
import { Field, SelectField } from "@/components/ui/field";
import { SectionHeader } from "@/components/ui/section-header";
import { SkeletonCard } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { apiGet, apiPost, apiPut, getErrorMessage } from "@/lib/api-client";
import { clearSession } from "@/lib/session";
import { queryKeys } from "@/lib/query-keys";
import type { DataEnvelope, Household, MessageEnvelope, UserSettings } from "@/lib/types/api";

type AccountModal = "profil" | "motdepasse" | "export" | "suppression" | null;

/** « Paramètres » — compte, rappels dans l'app, foyer, apparence et déconnexion (§14). */
export default function SettingsPage() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { success, error: toastError } = useToast();

  const [modal, setModal] = useState<AccountModal>(null);

  const settingsQuery = useQuery({
    queryKey: queryKeys.settings,
    queryFn: () => apiGet<DataEnvelope<UserSettings>>("/settings"),
  });

  const householdQuery = useQuery({
    queryKey: queryKeys.household.current,
    queryFn: () => apiGet<DataEnvelope<Household | null>>("/household"),
  });

  const settings = settingsQuery.data?.data;
  const household = householdQuery.data?.data ?? null;

  /** Enregistrement optimiste : on écrit tout de suite, on revient en arrière si le serveur refuse. */
  const update = useMutation({
    mutationFn: (patch: Partial<UserSettings>) =>
      apiPut<DataEnvelope<UserSettings>>("/settings", patch),
    onMutate: async (patch) => {
      await queryClient.cancelQueries({ queryKey: queryKeys.settings });
      const previous = queryClient.getQueryData<DataEnvelope<UserSettings>>(queryKeys.settings);
      if (previous) {
        queryClient.setQueryData<DataEnvelope<UserSettings>>(queryKeys.settings, {
          ...previous,
          data: { ...previous.data, ...patch },
        });
      }
      return { previous };
    },
    onError: (error, _patch, context) => {
      if (context?.previous) queryClient.setQueryData(queryKeys.settings, context.previous);
      toastError(getErrorMessage(error));
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: queryKeys.settings }),
  });

  const logout = useMutation({
    mutationFn: () => apiPost<MessageEnvelope>("/auth/logout"),
    onSettled: () => {
      clearSession();
      queryClient.clear();
      router.push("/login");
    },
  });

  if (settingsQuery.isPending) {
    return (
      <div className="space-y-6">
        <SectionHeader eyebrow="Compte" title="Paramètres" tone="violet" />
        <SkeletonCard />
      </div>
    );
  }

  if (settingsQuery.isError || !settings) {
    return (
      <ErrorState
        message={getErrorMessage(settingsQuery.error)}
        onRetry={() => settingsQuery.refetch()}
      />
    );
  }

  return (
    <div className="space-y-6">
      <SectionHeader
        eyebrow="Compte"
        title="Paramètres"
        subtitle="Tes réglages sont enregistrés au fur et à mesure."
        tone="violet"
      />

      <Card padding="md">
        <p className="mb-3 text-sm font-semibold text-slate-900">Compte</p>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => setModal("profil")}>
            Nom et e-mail
          </Button>
          <Button variant="secondary" onClick={() => setModal("motdepasse")}>
            Changer le mot de passe
          </Button>
          <Button variant="secondary" onClick={() => setModal("export")}>
            Exporter mes données
          </Button>
          <Button variant="danger" onClick={() => setModal("suppression")}>
            Supprimer mon compte
          </Button>
        </div>
      </Card>

      <Card padding="md">
        <p className="text-sm font-semibold text-slate-900">Rappels dans l’app</p>
        <p className="mb-3 text-xs text-slate-500">Aucune notification système pour l’instant.</p>
        <div className="divide-y divide-slate-100">
          <SwitchRow
            title="Alertes de péremption"
            description="Prévenir quand un produit du stock approche de sa date."
            checked={settings.notif_peremption}
            onChange={(checked) => update.mutate({ notif_peremption: checked })}
          />
          {settings.notif_peremption ? (
            <div className="py-3">
              <label htmlFor="jours" className="mb-1.5 block text-sm text-slate-700">
                Prévenir <span className="font-semibold">{settings.jours_alerte_peremption}</span> jour
                {settings.jours_alerte_peremption > 1 ? "s" : ""} avant
              </label>
              <input
                id="jours"
                type="range"
                min={1}
                max={7}
                step={1}
                value={settings.jours_alerte_peremption}
                onChange={(event) =>
                  update.mutate({ jours_alerte_peremption: Number(event.target.value) })
                }
                className="w-full max-w-sm accent-emerald-700"
              />
            </div>
          ) : null}
          <SwitchRow
            title="Rappel des repas"
            description="Signaler les repas planifiés que tu n’as pas encore enregistrés."
            checked={settings.notif_rappel_repas}
            onChange={(checked) => update.mutate({ notif_rappel_repas: checked })}
          />
          <SwitchRow
            title="Rappel des séances"
            description="Signaler les séances prévues aujourd’hui."
            checked={settings.notif_rappel_sport}
            onChange={(checked) => update.mutate({ notif_rappel_sport: checked })}
          />
          {settings.notif_rappel_repas || settings.notif_rappel_sport ? (
            <div className="py-3">
              <Field
                label="Heure du rappel"
                type="time"
                className="max-w-[12rem]"
                value={settings.heure_rappel ? settings.heure_rappel.slice(0, 5) : ""}
                onChange={(event) =>
                  update.mutate({ heure_rappel: event.target.value || null })
                }
              />
            </div>
          ) : null}
        </div>
      </Card>

      <Card padding="md">
        <p className="mb-3 text-sm font-semibold text-slate-900">Sport</p>
        <SwitchRow
          title="Séances proposées par l’IA"
          description="Quand l’IA est indisponible, les séances restent générées par les règles Mavi’oh."
          checked={settings.ia_seances}
          onChange={(checked) => update.mutate({ ia_seances: checked })}
        />
      </Card>

      {household ? (
        <Card padding="md">
          <p className="mb-3 text-sm font-semibold text-slate-900">Foyer · {household.name}</p>
          <SwitchRow
            title="Partager mon profil avec le foyer"
            description="Les membres voient tes objectifs et ton régime, jamais ton historique."
            checked={settings.partage_profil_foyer ?? true}
            onChange={(checked) => update.mutate({ partage_profil_foyer: checked })}
          />
        </Card>
      ) : null}

      <Card padding="md">
        <p className="mb-3 text-sm font-semibold text-slate-900">Apparence</p>
        <SelectField label="Thème" value="clair" disabled onChange={() => undefined}>
          <option value="clair">Clair · Sombre bientôt</option>
        </SelectField>
      </Card>

      <Card padding="md">
        <p className="mb-2 text-sm font-semibold text-slate-900">À propos</p>
        <ul className="space-y-1 text-sm text-slate-600">
          <li>Données nutritionnelles : Open Food Facts (ODbL).</li>
          <li>Les objectifs sont des estimations, pas un avis médical.</li>
        </ul>
      </Card>

      <div>
        <Button variant="secondary" onClick={() => logout.mutate()} loading={logout.isPending}>
          Déconnexion
        </Button>
      </div>

      <AccountModals open={modal} onClose={() => setModal(null)} onDone={(message) => success(message)} />
    </div>
  );
}
