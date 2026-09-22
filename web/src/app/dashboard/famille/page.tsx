"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CommonMealCard } from "@/components/family/common-meal-card";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ErrorState } from "@/components/ui/error-state";
import { Field } from "@/components/ui/field";
import { Pill } from "@/components/ui/pill";
import { SectionHeader } from "@/components/ui/section-header";
import { SkeletonCard } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { useMe } from "@/hooks/use-me";
import { apiDelete, apiGet, apiPost, apiPut, getErrorMessage } from "@/lib/api-client";
import { formatKcal } from "@/lib/format";
import { queryKeys } from "@/lib/query-keys";
import { REGIME_LABELS } from "@/lib/vocab";
import type {
  DataEnvelope,
  Household,
  HouseholdJoinResponse,
  HouseholdMember,
  HouseholdPreview,
  MessageEnvelope,
} from "@/lib/types/api";

const CODE_LENGTH = 8;

/** « Famille » — créer ou rejoindre un foyer, membres et repas commun (§10). */
export default function FamilyPage() {
  const queryClient = useQueryClient();
  const { success, error: toastError } = useToast();
  const me = useMe();

  const householdQuery = useQuery({
    queryKey: queryKeys.household.current,
    queryFn: () => apiGet<DataEnvelope<Household | null>>("/household"),
  });

  const household = householdQuery.data?.data ?? null;

  async function refresh() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: queryKeys.household.all }),
      queryClient.invalidateQueries({ queryKey: queryKeys.stocks.all }),
      queryClient.invalidateQueries({ queryKey: queryKeys.shopping.all }),
    ]);
  }

  if (householdQuery.isPending) {
    return (
      <div className="space-y-6">
        <SectionHeader eyebrow="Compte" title="Famille" tone="sky" />
        <SkeletonCard />
      </div>
    );
  }

  if (householdQuery.isError) {
    return (
      <ErrorState
        message={getErrorMessage(householdQuery.error)}
        onRetry={() => householdQuery.refetch()}
      />
    );
  }

  return (
    <div className="space-y-6">
      <SectionHeader
        eyebrow="Compte"
        title={household ? household.name : "Famille"}
        subtitle={
          household
            ? `${household.members.length} membre${household.members.length > 1 ? "s" : ""} · stock et liste de courses partagés`
            : "Partage ton stock, ta liste de courses et tes repas planifiés avec ton foyer."
        }
        tone="sky"
      />

      {household ? (
        <HouseholdView
          household={household}
          currentUserId={me.data?.id ?? null}
          onChanged={refresh}
          onSuccess={success}
          onError={toastError}
        />
      ) : (
        <NoHousehold
          firstName={me.data?.name?.split(" ")[0] ?? ""}
          onJoined={refresh}
          onSuccess={success}
        />
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */

function NoHousehold({
  firstName,
  onJoined,
  onSuccess,
}: {
  firstName: string;
  onJoined: () => Promise<void>;
  onSuccess: (message: string) => void;
}) {
  const [name, setName] = useState(firstName ? `Foyer de ${firstName}` : "Mon foyer");
  const [createError, setCreateError] = useState<string | null>(null);

  const [code, setCode] = useState("");
  const [joinError, setJoinError] = useState<string | null>(null);
  const [preview, setPreview] = useState<HouseholdPreview | null>(null);

  const create = useMutation({
    mutationFn: () => apiPost<DataEnvelope<Household>>("/household", { name: name.trim() }),
    onSuccess: async () => {
      await onJoined();
      onSuccess("Foyer créé.");
    },
    onError: (error) => setCreateError(getErrorMessage(error)),
  });

  const lookup = useMutation({
    mutationFn: () =>
      apiGet<DataEnvelope<HouseholdPreview>>("/household/preview", { invite_code: code }),
    onSuccess: (response) => {
      setJoinError(null);
      setPreview(response.data);
    },
    onError: (error) => setJoinError(getErrorMessage(error)),
  });

  const join = useMutation({
    mutationFn: () => apiPost<HouseholdJoinResponse>("/household/join", { invite_code: code }),
    onSuccess: async (response) => {
      setPreview(null);
      await onJoined();
      onSuccess(
        response.merged_stock_items > 0
          ? `Foyer rejoint · ${response.merged_stock_items} article${response.merged_stock_items > 1 ? "s" : ""} fusionné${response.merged_stock_items > 1 ? "s" : ""}.`
          : "Foyer rejoint.",
      );
    },
    onError: (error) => setJoinError(getErrorMessage(error)),
  });

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card padding="md">
        <p className="mb-1 text-sm font-semibold text-slate-900">Créer un foyer</p>
        <p className="mb-3 text-sm text-slate-600">
          Tu deviens propriétaire et tu obtiens un code d’invitation à partager.
        </p>
        {createError ? (
          <Banner tone="error" className="mb-3" onClose={() => setCreateError(null)}>
            {createError}
          </Banner>
        ) : null}
        <Field label="Nom du foyer" value={name} onChange={(event) => setName(event.target.value)} />
        <Button className="mt-3" loading={create.isPending} onClick={() => create.mutate()}>
          Créer le foyer
        </Button>
      </Card>

      <Card padding="md">
        <p className="mb-1 text-sm font-semibold text-slate-900">Rejoindre un foyer</p>
        <p className="mb-3 text-sm text-slate-600">
          Saisis le code à huit caractères transmis par le propriétaire.
        </p>
        {joinError ? (
          <Banner tone="error" className="mb-3" onClose={() => setJoinError(null)}>
            {joinError}
          </Banner>
        ) : null}
        <div className="flex flex-wrap items-end gap-2">
          <Field
            label="Code d’invitation"
            className="font-mono tracking-[0.35em] uppercase"
            maxLength={CODE_LENGTH}
            value={code}
            onChange={(event) => setCode(event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ""))}
          />
          <Button
            variant="secondary"
            onClick={async () => {
              const text = await navigator.clipboard.readText();
              setCode(text.toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, CODE_LENGTH));
            }}
          >
            Coller
          </Button>
          <Button
            disabled={code.length !== CODE_LENGTH}
            loading={lookup.isPending}
            onClick={() => lookup.mutate()}
          >
            Rejoindre
          </Button>
        </div>
      </Card>

      <ConfirmDialog
        open={preview !== null}
        onClose={() => setPreview(null)}
        title={preview ? `Rejoindre « ${preview.name} » ?` : ""}
        message={
          preview
            ? `Ce foyer compte ${preview.members_count} membre${preview.members_count > 1 ? "s" : ""}. Ton stock personnel sera fusionné avec celui du foyer et visible par ses membres.`
            : ""
        }
        confirmLabel="Rejoindre"
        onConfirm={async () => {
          await join.mutateAsync();
        }}
      />
    </div>
  );
}

function HouseholdView({
  household,
  currentUserId,
  onChanged,
  onSuccess,
  onError,
}: {
  household: Household;
  currentUserId: number | null;
  onChanged: () => Promise<void>;
  onSuccess: (message: string) => void;
  onError: (message: string) => void;
}) {
  const isOwner = household.role === "proprietaire";
  const [copied, setCopied] = useState(false);
  const [regenerateOpen, setRegenerateOpen] = useState(false);
  const [leaveOpen, setLeaveOpen] = useState(false);
  const [dissolveOpen, setDissolveOpen] = useState(false);
  const [removing, setRemoving] = useState<HouseholdMember | null>(null);

  const me = household.members.find((member) => member.user_id === currentUserId);

  const regenerate = useMutation({
    mutationFn: () => apiPost<DataEnvelope<Household>>("/household/regenerate-code"),
    onSuccess: async () => {
      setRegenerateOpen(false);
      await onChanged();
      onSuccess("Nouveau code généré.");
    },
    onError: (error) => onError(getErrorMessage(error)),
  });

  const share = useMutation({
    mutationFn: (value: boolean) =>
      apiPut<DataEnvelope<HouseholdMember>>("/household/members/me", { share_profile: value }),
    onSuccess: onChanged,
    onError: (error) => onError(getErrorMessage(error)),
  });

  const removeMember = useMutation({
    mutationFn: (member: HouseholdMember) =>
      apiDelete<MessageEnvelope>(`/household/members/${member.user_id}`),
    onSuccess: async () => {
      setRemoving(null);
      await onChanged();
      onSuccess("Membre retiré du foyer.");
    },
    onError: (error) => onError(getErrorMessage(error)),
  });

  const leave = useMutation({
    mutationFn: () => apiPost<MessageEnvelope>("/household/leave"),
    onSuccess: async () => {
      setLeaveOpen(false);
      await onChanged();
      onSuccess("Tu as quitté le foyer.");
    },
    onError: (error) => onError(getErrorMessage(error)),
  });

  const dissolve = useMutation({
    mutationFn: () => apiDelete<MessageEnvelope>("/household"),
    onSuccess: async () => {
      setDissolveOpen(false);
      await onChanged();
      onSuccess("Foyer supprimé.");
    },
    onError: (error) => onError(getErrorMessage(error)),
  });

  return (
    <div className="space-y-4">
      {isOwner && household.invite_code ? (
        <Card padding="md">
          <p className="mb-2 text-sm font-semibold text-slate-900">Code d’invitation</p>
          <div className="flex flex-wrap items-center gap-3">
            <span className="rounded-2xl bg-slate-950 px-4 py-2 font-mono text-lg tracking-[0.3em] text-white">
              {household.invite_code}
            </span>
            <Button
              variant="secondary"
              onClick={async () => {
                await navigator.clipboard.writeText(household.invite_code ?? "");
                setCopied(true);
                onSuccess("Code copié.");
              }}
            >
              {copied ? "Copié" : "Copier"}
            </Button>
            <Button variant="ghost" onClick={() => setRegenerateOpen(true)}>
              Nouveau code
            </Button>
          </div>
        </Card>
      ) : null}

      <Card padding="md">
        <p className="mb-3 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
          Membres
        </p>
        <ul className="divide-y divide-slate-100">
          {household.members.map((member) => (
            <li key={member.user_id} className="flex flex-wrap items-center gap-3 py-3">
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-slate-900">{member.name}</p>
                <div className="mt-1 flex flex-wrap items-center gap-1.5">
                  <Pill tone={member.role === "proprietaire" ? "emerald" : "slate"}>
                    {member.role === "proprietaire" ? "Propriétaire" : "Membre"}
                  </Pill>
                  {member.share_profile && member.calories_cibles ? (
                    <Pill tone="sky">{formatKcal(member.calories_cibles)}</Pill>
                  ) : null}
                  {member.share_profile && member.regime ? (
                    <Pill tone="violet">{REGIME_LABELS[member.regime]}</Pill>
                  ) : null}
                  {!member.share_profile ? (
                    <span className="text-xs text-slate-500">profil non partagé</span>
                  ) : null}
                </div>
              </div>
              {isOwner && member.user_id !== currentUserId ? (
                <Button variant="ghost" onClick={() => setRemoving(member)}>
                  Retirer
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
      </Card>

      <CommonMealCard household={household} onCreated={onChanged} onSuccess={onSuccess} />

      <Card padding="md">
        <p className="mb-3 text-sm font-semibold text-slate-900">Mon profil dans le foyer</p>
        <label className="flex items-center gap-3 text-sm text-slate-700">
          <input
            type="checkbox"
            checked={me?.share_profile ?? true}
            onChange={(event) => share.mutate(event.target.checked)}
            className="size-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
          />
          Partager mes objectifs et mon régime avec les membres du foyer
        </label>
        <p className="mt-1 text-xs text-slate-500">
          Ton historique de repas reste strictement personnel dans tous les cas.
        </p>
      </Card>

      <Card padding="md" tone="rose">
        <p className="mb-2 text-sm font-semibold text-rose-900">Zone sensible</p>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => setLeaveOpen(true)}>
            Quitter le foyer
          </Button>
          {isOwner ? (
            <Button variant="danger" onClick={() => setDissolveOpen(true)}>
              Supprimer le foyer
            </Button>
          ) : null}
        </div>
      </Card>

      <ConfirmDialog
        open={regenerateOpen}
        onClose={() => setRegenerateOpen(false)}
        title="Générer un nouveau code ?"
        message="L’ancien code cessera immédiatement de fonctionner."
        confirmLabel="Nouveau code"
        onConfirm={async () => {
          await regenerate.mutateAsync();
        }}
      />

      <ConfirmDialog
        open={removing !== null}
        onClose={() => setRemoving(null)}
        title={removing ? `Retirer ${removing.name} ?` : ""}
        message="Cette personne retrouvera un stock personnel vide. Le stock du foyer est conservé."
        confirmLabel="Retirer"
        danger
        onConfirm={async () => {
          if (removing) await removeMember.mutateAsync(removing);
        }}
      />

      <ConfirmDialog
        open={leaveOpen}
        onClose={() => setLeaveOpen(false)}
        title="Quitter ce foyer ?"
        message="Le stock, la liste de courses et le planning restent au foyer. Tu repars avec un stock personnel vide."
        confirmLabel="Quitter"
        danger
        onConfirm={async () => {
          await leave.mutateAsync();
        }}
      />

      <ConfirmDialog
        open={dissolveOpen}
        onClose={() => setDissolveOpen(false)}
        title="Supprimer le foyer ?"
        message="Le stock, la liste de courses et le planning reviennent au propriétaire. Les autres membres retrouvent un stock personnel vide."
        confirmLabel="Supprimer le foyer"
        danger
        onConfirm={async () => {
          await dissolve.mutateAsync();
        }}
      />
    </div>
  );
}
