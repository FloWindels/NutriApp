"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  CaloriesSourcePill,
  GeneratedByBadge,
  SessionStatusPill,
} from "@/components/sport/shared";
import {
  describeSets,
  sportApi,
  SPORT_MUTATION_KEYS,
  useSportSession,
} from "@/components/sport/sport-api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ErrorState } from "@/components/ui/error-state";
import { Field } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { Pill } from "@/components/ui/pill";
import { SectionHeader } from "@/components/ui/section-header";
import { SkeletonCard } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { formatKcal, formatMinutes, formatRelativeDay, parseDecimal } from "@/lib/format";
import { useResetOnChange } from "@/lib/use-reset-on-change";
import { BLOCK_LABELS } from "@/lib/vocab";
import type {
  SessionCompleteResponse,
  WorkoutBlockKey,
  WorkoutExercise,
  WorkoutSession,
} from "@/lib/types/api";

const BLOCK_ORDER: WorkoutBlockKey[] = ["echauffement", "principal", "retour_au_calme"];

const RPE_LABELS: Record<number, string> = {
  1: "très facile",
  3: "facile",
  5: "modéré",
  7: "difficile",
  9: "très difficile",
  10: "maximal",
};

type LocalExercise = { completed: boolean; sets: string; reps: string; weight: string };
type LocalState = Record<number, LocalExercise>;

export function SessionDetail({ sessionId }: { sessionId: string }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const { success, error: toastError } = useToast();

  const query = useSportSession(sessionId);
  const session = query.data;

  const [local, setLocal] = useState<LocalState>({});
  const [completeOpen, setCompleteOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [caloriesOpen, setCaloriesOpen] = useState(false);
  const [result, setResult] = useState<SessionCompleteResponse | null>(null);
  const [banner, setBanner] = useState<string | null>(null);

  // Les champs locaux repartent de la séance dès qu'elle change (ajustement au rendu).
  useResetOnChange(session ? `${session.id}:${session.status}:${session.completed_at ?? ""}` : "", () => {
    if (!session) return;
    const next: LocalState = {};
    for (const exercise of session.exercises ?? []) {
      next[exercise.id] = {
        completed: exercise.completed,
        sets: exercise.sets !== null ? String(exercise.sets) : "",
        reps: exercise.reps !== null ? String(exercise.reps) : "",
        weight: exercise.weight_kg !== null ? String(exercise.weight_kg) : "",
      };
    }
    setLocal(next);
  });

  async function invalidate() {
    await Promise.all(SPORT_MUTATION_KEYS.map((key) => queryClient.invalidateQueries({ queryKey: key })));
  }

  const start = useMutation({
    mutationFn: () => sportApi.startSession(Number(sessionId)),
    onSuccess: async () => {
      await invalidate();
      success("Séance démarrée. Bon entraînement !");
    },
    onError: (err) => setBanner(getErrorMessage(err)),
  });

  const complete = useMutation({
    mutationFn: (input: { duration_min: number; rpe: number }) =>
      sportApi.completeSession(Number(sessionId), {
        ...input,
        exercises: Object.entries(local).map(([id, row]) => ({
          id: Number(id),
          completed: row.completed,
          sets: row.sets.trim() ? Math.round(parseDecimal(row.sets) ?? 0) : null,
          reps: row.reps.trim() ? Math.round(parseDecimal(row.reps) ?? 0) : null,
          weight_kg: row.weight.trim() ? parseDecimal(row.weight) : null,
        })),
      }),
    onSuccess: async (response) => {
      setCompleteOpen(false);
      setResult(response);
      await invalidate();
    },
    onError: (err) => setBanner(getErrorMessage(err)),
  });

  const cancel = useMutation({
    mutationFn: () => sportApi.cancelSession(Number(sessionId)),
    onSuccess: async () => {
      setCancelOpen(false);
      await invalidate();
      success("Séance annulée.");
    },
    onError: (err) => toastError(getErrorMessage(err)),
  });

  const remove = useMutation({
    mutationFn: () => sportApi.deleteSession(Number(sessionId)),
    onSuccess: async () => {
      setDeleteOpen(false);
      await invalidate();
      success("Séance supprimée.");
      router.push("/dashboard/sport?onglet=seances");
    },
    onError: (err) => toastError(getErrorMessage(err)),
  });

  const setCalories = useMutation({
    mutationFn: (value: number | null) =>
      sportApi.updateSession(Number(sessionId), { calories_burned: value }),
    onSuccess: async () => {
      setCaloriesOpen(false);
      await invalidate();
      success("Calories mises à jour.");
    },
    onError: (err) => toastError(getErrorMessage(err)),
  });

  if (query.isPending) {
    return (
      <div className="space-y-6">
        <SectionHeader eyebrow="Sport" title="Séance" tone="amber" />
        <SkeletonCard />
      </div>
    );
  }

  if (query.isError || !session) {
    return (
      <ErrorState
        title="Séance introuvable"
        message={query.error ? getErrorMessage(query.error) : "Cette séance n’existe plus."}
        onRetry={() => query.refetch()}
      />
    );
  }

  const blocks = groupBlocks(session.exercises ?? []);
  const done = Object.values(local).filter((row) => row.completed).length;
  const total = Object.keys(local).length;

  return (
    <div className="space-y-6">
      <SectionHeader
        eyebrow={formatRelativeDay(session.date)}
        title={session.title}
        subtitle={[
          formatMinutes(session.duration_min),
          session.sport_name && session.sport_name !== session.title ? session.sport_name : null,
        ]
          .filter(Boolean)
          .join(" · ")}
        tone="amber"
        actions={
          <Link href="/dashboard/sport?onglet=seances" className="text-sm text-slate-500 hover:text-slate-900">
            ← Toutes les séances
          </Link>
        }
      />

      {banner ? (
        <Banner tone="error" onClose={() => setBanner(null)}>
          {banner}
        </Banner>
      ) : null}

      {result ? <ResultCard result={result} onClose={() => setResult(null)} /> : null}

      <Card padding="md">
        <div className="flex flex-wrap items-center gap-2">
          <SessionStatusPill status={session.status} />
          {session.generated_by ? (
            <GeneratedByBadge generatedBy={session.generated_by} model={session.llm_model} />
          ) : null}
          {session.calories_burned !== null ? (
            <>
              <Pill tone="amber">{formatKcal(session.calories_burned)}</Pill>
              <CaloriesSourcePill source={session.calories_source} />
            </>
          ) : null}
          {session.started_at && session.status === "en_cours" ? (
            <ElapsedPill startedAt={session.started_at} />
          ) : null}
          {total > 0 ? (
            <span className="text-sm text-slate-500">
              {done} / {total} exercice{total > 1 ? "s" : ""}
            </span>
          ) : null}
        </div>

        <div className="mt-4 flex flex-wrap gap-2">
          {session.status === "prevue" ? (
            <Button onClick={() => start.mutate()} loading={start.isPending}>
              Commencer
            </Button>
          ) : null}
          {session.status === "en_cours" ? (
            <Button onClick={() => setCompleteOpen(true)}>Terminer</Button>
          ) : null}
          {session.status === "terminee" ? (
            <Button variant="secondary" onClick={() => setCaloriesOpen(true)}>
              Corriger les calories
            </Button>
          ) : null}
          {session.status !== "annulee" && session.status !== "terminee" ? (
            <Button variant="secondary" onClick={() => setCancelOpen(true)}>
              Annuler la séance
            </Button>
          ) : null}
          <Button variant="danger" onClick={() => setDeleteOpen(true)}>
            Supprimer
          </Button>
        </div>
      </Card>

      {BLOCK_ORDER.filter((key) => blocks[key]?.length).map((key) => (
        <Card key={key} padding="md">
          <p className="mb-3 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
            {BLOCK_LABELS[key]}
          </p>
          <ul className="space-y-2">
            {blocks[key]!.map((exercise) => (
              <ExerciseRow
                key={exercise.id}
                exercise={exercise}
                state={local[exercise.id]}
                editable={session.status === "en_cours"}
                onChange={(next) => setLocal((current) => ({ ...current, [exercise.id]: next }))}
              />
            ))}
          </ul>
        </Card>
      ))}

      <CompleteModal
        open={completeOpen}
        onClose={() => setCompleteOpen(false)}
        defaultDuration={session.duration_min}
        pending={complete.isPending}
        onSubmit={(values) => complete.mutate(values)}
      />

      <CaloriesModal
        open={caloriesOpen}
        onClose={() => setCaloriesOpen(false)}
        current={session.calories_burned}
        pending={setCalories.isPending}
        onSubmit={(value) => setCalories.mutate(value)}
      />

      <ConfirmDialog
        open={cancelOpen}
        onClose={() => setCancelOpen(false)}
        title="Annuler cette séance ?"
        message="Elle restera dans ton historique avec le statut « annulée »."
        confirmLabel="Annuler la séance"
        onConfirm={async () => {
          await cancel.mutateAsync();
        }}
      />

      <ConfirmDialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        title="Supprimer cette séance ?"
        message="Elle disparaîtra de ton historique et ses calories ne compteront plus dans ton budget."
        confirmLabel="Supprimer"
        danger
        onConfirm={async () => {
          await remove.mutateAsync();
        }}
      />
    </div>
  );
}

/* ------------------------------------------------------------------ */

function groupBlocks(exercises: WorkoutExercise[]): Partial<Record<WorkoutBlockKey, WorkoutExercise[]>> {
  const map: Partial<Record<WorkoutBlockKey, WorkoutExercise[]>> = {};

  for (const exercise of [...exercises].sort((a, b) => a.position - b.position)) {
    const list = map[exercise.block] ?? [];
    list.push(exercise);
    map[exercise.block] = list;
  }

  return map;
}

function ExerciseRow({
  exercise,
  state,
  editable,
  onChange,
}: {
  exercise: WorkoutExercise;
  state?: LocalExercise;
  editable: boolean;
  onChange: (next: LocalExercise) => void;
}) {
  const row = state ?? { completed: exercise.completed, sets: "", reps: "", weight: "" };
  const details = describeSets(exercise);

  return (
    <li
      className={cn(
        "rounded-2xl border px-4 py-3 transition",
        row.completed ? "border-emerald-200 bg-emerald-50/60" : "border-slate-200 bg-white",
      )}
    >
      <div className="flex flex-wrap items-center gap-3">
        <label className="flex min-w-0 flex-1 items-center gap-3">
          <input
            type="checkbox"
            checked={row.completed}
            disabled={!editable}
            onChange={(event) => onChange({ ...row, completed: event.target.checked })}
            className="size-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
          />
          <span className="min-w-0">
            <span className="block truncate text-sm font-medium text-slate-900">{exercise.name}</span>
            {details ? <span className="block text-xs text-slate-500">{details}</span> : null}
          </span>
        </label>

        {exercise.rest_sec ? <Pill tone="slate">repos {exercise.rest_sec} s</Pill> : null}
      </div>

      {editable ? (
        <div className="mt-3 grid gap-3 sm:grid-cols-3">
          <Field
            label="Séries"
            inputMode="numeric"
            value={row.sets}
            onChange={(event) => onChange({ ...row, sets: event.target.value })}
          />
          <Field
            label="Répétitions"
            inputMode="numeric"
            value={row.reps}
            onChange={(event) => onChange({ ...row, reps: event.target.value })}
          />
          <Field
            label="Charge (kg)"
            inputMode="decimal"
            value={row.weight}
            onChange={(event) => onChange({ ...row, weight: event.target.value })}
          />
        </div>
      ) : null}

      {exercise.instructions ? (
        <details className="mt-2">
          <summary className="cursor-pointer text-xs text-slate-500 hover:text-slate-900">
            Comment faire ?
          </summary>
          <p className="mt-1 text-sm text-slate-600">{exercise.instructions}</p>
        </details>
      ) : null}
    </li>
  );
}

/** Chronomètre calculé depuis started_at : survit à un rechargement de page. */
function ElapsedPill({ startedAt }: { startedAt: string }) {
  const started = useMemo(() => new Date(startedAt).getTime(), [startedAt]);
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);

  const seconds = Math.max(0, Math.floor((now - started) / 1000));
  const mm = String(Math.floor(seconds / 60)).padStart(2, "0");
  const ss = String(seconds % 60).padStart(2, "0");

  return (
    <Pill tone="emerald" dot>
      {mm}:{ss}
    </Pill>
  );
}

function CompleteModal({
  open,
  onClose,
  defaultDuration,
  pending,
  onSubmit,
}: {
  open: boolean;
  onClose: () => void;
  defaultDuration: number;
  pending: boolean;
  onSubmit: (values: { duration_min: number; rpe: number }) => void;
}) {
  const [duration, setDuration] = useState(String(defaultDuration));
  const [rpe, setRpe] = useState(6);

  useResetOnChange(String(open), () => {
    if (!open) return;
    setDuration(String(defaultDuration));
    setRpe(6);
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Terminer la séance"
      description="Les calories sont recalculées à partir de la durée réelle et de l’effort ressenti."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button
            loading={pending}
            onClick={() =>
              onSubmit({
                duration_min: Math.max(1, Math.round(parseDecimal(duration) ?? defaultDuration)),
                rpe,
              })
            }
          >
            Terminer
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field
          label="Durée réelle (minutes)"
          inputMode="numeric"
          value={duration}
          onChange={(event) => setDuration(event.target.value)}
        />
        <div>
          <label htmlFor="rpe" className="mb-1.5 block text-sm font-medium text-slate-700">
            Effort ressenti : <span className="font-semibold">{rpe}</span> — {rpeLabel(rpe)}
          </label>
          <input
            id="rpe"
            type="range"
            min={1}
            max={10}
            step={1}
            value={rpe}
            onChange={(event) => setRpe(Number(event.target.value))}
            className="w-full accent-emerald-700"
          />
          <div className="flex justify-between text-xs text-slate-500">
            <span>facile</span>
            <span>maximal</span>
          </div>
        </div>
      </div>
    </Modal>
  );
}

function rpeLabel(value: number): string {
  const keys = Object.keys(RPE_LABELS)
    .map(Number)
    .sort((a, b) => a - b);
  let label = RPE_LABELS[keys[0]];
  for (const key of keys) if (value >= key) label = RPE_LABELS[key];
  return label;
}

function CaloriesModal({
  open,
  onClose,
  current,
  pending,
  onSubmit,
}: {
  open: boolean;
  onClose: () => void;
  current: number | null;
  pending: boolean;
  onSubmit: (value: number | null) => void;
}) {
  const [value, setValue] = useState(current !== null ? String(current) : "");

  useResetOnChange(`${open}:${current ?? ""}`, () => {
    if (!open) return;
    setValue(current !== null ? String(current) : "");
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Corriger les calories"
      description="Laisse le champ vide pour revenir au calcul automatique à partir de la formule MET."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button
            loading={pending}
            onClick={() => onSubmit(value.trim() ? parseDecimal(value) : null)}
          >
            Enregistrer
          </Button>
        </>
      }
    >
      <Field
        label="Calories brûlées (kcal)"
        inputMode="decimal"
        value={value}
        placeholder="Automatique"
        onChange={(event) => setValue(event.target.value)}
      />
    </Modal>
  );
}

function ResultCard({
  result,
  onClose,
}: {
  result: SessionCompleteResponse;
  onClose: () => void;
}) {
  const session: WorkoutSession = result.data;

  return (
    <Card tone="emerald" padding="md">
      <p className="text-lg font-semibold text-emerald-900">
        {formatKcal(session.calories_burned ?? 0)} brûlées · +{result.nutrition.calories_bonus} kcal
        dans ton budget du jour
      </p>
      <p className="mt-1 text-sm text-emerald-800">{result.nutrition.explication}</p>
      {result.reco_post ? (
        <p className="mt-2 text-sm text-emerald-900">{result.reco_post}</p>
      ) : null}
      <div className="mt-3 flex flex-wrap gap-2">
        <Link href="/dashboard/historique-repas-journee">
          <Button variant="secondary">Voir mon budget</Button>
        </Link>
        <Button variant="ghost" onClick={onClose}>
          Fermer
        </Button>
      </div>
    </Card>
  );
}

export default SessionDetail;
