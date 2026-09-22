"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { Disclosure } from "@/components/sport/chips";
import { ExercisePickerModal } from "@/components/sport/exercise-picker";
import { describeSets, sportApi, useInvalidateSport } from "@/components/sport/sport-api";
import { GeneratedByBadge } from "@/components/sport/shared";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { Field } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { EstimatePill, Pill } from "@/components/ui/pill";
import { useToast } from "@/components/ui/toast";
import { getErrorMessage } from "@/lib/api-client";
import { formatKcal, formatMinutes, todayIso } from "@/lib/format";
import { messages } from "@/lib/messages";
import type { Exercise, ProposalExercise, SessionInput, WorkoutProposal } from "@/lib/types/api";
import {
  BLOCK_LABELS,
  EXERCISE_CATEGORY_LABELS,
  FOCUS_LABELS,
  MUSCLE_GROUP_LABELS,
  SPORT_LIEU_LABELS,
  labelFor,
} from "@/lib/vocab";

/** Renders a generated proposal with its actions (addendum §D « ProposalScreen »). */

export type ProposalViewProps = {
  proposal: WorkoutProposal;
  onProposalChange: (next: WorkoutProposal) => void;
  onRegenerate: () => void;
  regenerating?: boolean;
  /** Back to the form. */
  onBack?: () => void;
  /** Plan this proposal belongs to, when generated from the calendar. */
  sportPlanId?: number | null;
  defaultDate?: string;
  defaultTime?: string | null;
};

type Target = { blockIndex: number; exerciseIndex: number } | null;

export function ProposalView({
  proposal,
  onProposalChange,
  onRegenerate,
  regenerating = false,
  onBack,
  sportPlanId = null,
  defaultDate,
  defaultTime,
}: ProposalViewProps) {
  const router = useRouter();
  const invalidate = useInvalidateSport();
  const { success, error: toastError } = useToast();
  const [target, setTarget] = useState<Target>(null);
  const [planOpen, setPlanOpen] = useState(false);
  const [planDate, setPlanDate] = useState(defaultDate ?? todayIso());
  const [planTime, setPlanTime] = useState(defaultTime ? defaultTime.slice(0, 5) : "");

  function buildInput(extra: Partial<SessionInput>): SessionInput {
    const { is_estimate: _ignored, ...rest } = proposal;
    void _ignored;
    return {
      ...rest,
      date: defaultDate ?? todayIso(),
      title: proposal.title,
      duration_min: proposal.duration_min,
      kind: "seance",
      sport_plan_id: sportPlanId,
      ...extra,
    };
  }

  const planMutation = useMutation({
    mutationFn: () =>
      sportApi.createSession(
        buildInput({ date: planDate, planned_at: planTime || null, status: "prevue" }),
      ),
    onSuccess: async () => {
      await invalidate();
      setPlanOpen(false);
      success("Séance planifiée.");
    },
    onError: (error) => toastError("Impossible de planifier la séance", getErrorMessage(error)),
  });

  const startMutation = useMutation({
    mutationFn: async () => {
      const created = await sportApi.createSession(buildInput({ status: "prevue" }));
      const started = await sportApi.startSession(created.data.id);
      return started.data;
    },
    onSuccess: async (session) => {
      await invalidate();
      router.push(`/dashboard/sport/${session.id}`);
    },
    onError: (error) => toastError("Impossible de démarrer la séance", getErrorMessage(error)),
  });

  function replaceExercise(exercise: Exercise) {
    if (!target) return;
    const next: WorkoutProposal = {
      ...proposal,
      blocks: proposal.blocks.map((block, blockIndex) =>
        blockIndex !== target.blockIndex
          ? block
          : {
              ...block,
              exercises: block.exercises.map((row, exerciseIndex) =>
                exerciseIndex !== target.exerciseIndex
                  ? row
                  : ({
                      ...row,
                      exercise_id: exercise.id,
                      name: exercise.name,
                      category: exercise.category,
                      muscle_group: exercise.muscle_group,
                      equipment: exercise.equipment,
                      sets: row.sets ?? exercise.default_sets,
                      reps: row.reps ?? exercise.default_reps,
                      duration_sec: row.duration_sec ?? exercise.default_duration_sec,
                      instructions: exercise.instructions,
                      met: exercise.met,
                    } satisfies ProposalExercise),
              ),
            },
      ),
    };
    onProposalChange(next);
    setTarget(null);
  }

  const busy = planMutation.isPending || startMutation.isPending || regenerating;

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader
          title={proposal.title}
          subtitle={[
            formatMinutes(proposal.duration_min),
            proposal.lieu ? labelFor(SPORT_LIEU_LABELS, proposal.lieu) : null,
          ]
            .filter(Boolean)
            .join(" · ")}
          actions={<GeneratedByBadge generatedBy={proposal.generated_by} model={proposal.llm_model} />}
        />

        <div className="mt-3 flex flex-wrap items-center gap-2">
          <Pill tone="amber">{`~${formatKcal(proposal.calories_estimate)}`}</Pill>
          <EstimatePill />
          {proposal.focus.map((focus) => (
            <Pill key={focus} tone="sky">
              {labelFor(FOCUS_LABELS, focus)}
            </Pill>
          ))}
        </div>

        {proposal.warnings.length > 0 ? (
          <Banner tone="warning" className="mt-4" title="À garder en tête">
            <ul className="list-disc space-y-1 pl-4">
              {proposal.warnings.map((warning) => (
                <li key={warning}>{warning}</li>
              ))}
            </ul>
          </Banner>
        ) : null}

        {proposal.explication.length > 0 ? (
          <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
              Pourquoi cette séance
            </p>
            <ul className="mt-2 list-disc space-y-1 pl-4 text-sm leading-6 text-slate-600">
              {proposal.explication.map((line) => (
                <li key={line}>{line}</li>
              ))}
            </ul>
          </div>
        ) : null}

        <div className="mt-5 flex flex-wrap gap-2">
          <Button onClick={() => startMutation.mutate()} loading={startMutation.isPending} disabled={busy}>
            Commencer
          </Button>
          <Button variant="secondary" onClick={() => setPlanOpen(true)} disabled={busy}>
            Planifier…
          </Button>
          <Button variant="secondary" onClick={onRegenerate} loading={regenerating} disabled={busy}>
            Régénérer
          </Button>
          {onBack ? (
            <Button variant="ghost" onClick={onBack} disabled={busy}>
              Modifier mes critères
            </Button>
          ) : null}
        </div>
      </Card>

      {proposal.blocks.map((block, blockIndex) => (
        <Card key={`${block.key}-${blockIndex}`}>
          <CardHeader title={labelFor(BLOCK_LABELS, block.key, block.name)} />
          <ul className="mt-3 space-y-2">
            {block.exercises.map((exercise, exerciseIndex) => (
              <li
                key={`${exercise.name}-${exerciseIndex}`}
                className="rounded-2xl border border-slate-200 bg-white px-4 py-3"
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-slate-900">{exercise.name}</p>
                    <p className="mt-0.5 text-xs text-slate-500">
                      {[
                        describeSets(exercise),
                        exercise.rest_sec ? `repos ${exercise.rest_sec} s` : null,
                        labelFor(EXERCISE_CATEGORY_LABELS, exercise.category),
                        exercise.muscle_group ? labelFor(MUSCLE_GROUP_LABELS, exercise.muscle_group) : null,
                      ]
                        .filter(Boolean)
                        .join(" · ")}
                    </p>
                  </div>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => setTarget({ blockIndex, exerciseIndex })}
                  >
                    Remplacer
                  </Button>
                </div>
                <Disclosure label="Voir les consignes" className="mt-2">
                  {exercise.instructions || "Aucune consigne détaillée pour cet exercice."}
                </Disclosure>
              </li>
            ))}
          </ul>
        </Card>
      ))}

      <p className="px-1 text-xs leading-5 text-slate-500">{messages.sportDisclaimer}</p>

      <ExercisePickerModal open={target !== null} onClose={() => setTarget(null)} onPick={replaceExercise} />

      <Modal
        open={planOpen}
        onClose={() => setPlanOpen(false)}
        title="Planifier…"
        description="Choisis le jour et l’heure de cette séance."
        size="sm"
        locked={planMutation.isPending}
        footer={
          <>
            <Button variant="secondary" onClick={() => setPlanOpen(false)} disabled={planMutation.isPending}>
              Annuler
            </Button>
            <Button onClick={() => planMutation.mutate()} loading={planMutation.isPending}>
              Planifier
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field
            label="Date de la séance"
            type="date"
            value={planDate}
            onChange={(event) => setPlanDate(event.target.value)}
          />
          <Field
            label="Heure de la séance"
            type="time"
            value={planTime}
            onChange={(event) => setPlanTime(event.target.value)}
          />
        </div>
      </Modal>
    </div>
  );
}

export default ProposalView;
