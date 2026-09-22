"use client";

import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { sportApi } from "@/components/sport/sport-api";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Field } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { SkeletonList } from "@/components/ui/skeleton";
import { getErrorMessage } from "@/lib/api-client";
import { queryKeys } from "@/lib/query-keys";
import type { Exercise } from "@/lib/types/api";
import { EXERCISE_CATEGORY_LABELS, MATERIEL_LABELS, MUSCLE_GROUP_LABELS, labelFor } from "@/lib/vocab";
import { useResetOnChange } from "@/lib/use-reset-on-change";

/** Catalogue picker used by « Remplacer un exercice » (`GET /sport/exercises`). */

export type ExercisePickerProps = {
  open: boolean;
  onClose: () => void;
  onPick: (exercise: Exercise) => void;
};

export function ExercisePickerModal({ open, onClose, onPick }: ExercisePickerProps) {
  const [query, setQuery] = useState("");
  const [debounced, setDebounced] = useState("");

  useResetOnChange(String(open), () => {
    if (!open) {
      setQuery("");
      setDebounced("");
    }
  });

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(query.trim()), 250);
    return () => clearTimeout(timer);
  }, [query]);

  const params = debounced ? { q: debounced } : {};
  const exercisesQuery = useQuery({
    queryKey: queryKeys.sport.exercises(params),
    queryFn: async () => (await sportApi.exercises(params)).data,
    enabled: open,
    staleTime: 5 * 60 * 1000,
  });

  const exercises = exercisesQuery.data ?? [];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Remplacer l’exercice"
      description="Choisis un exercice du catalogue Mavi’oh."
      size="lg"
    >
      <div className="space-y-4">
        <Field
          label="Rechercher un exercice"
          type="search"
          placeholder="Squat, gainage, fentes…"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          autoComplete="off"
        />

        {exercisesQuery.isPending ? (
          <SkeletonList rows={5} />
        ) : exercisesQuery.isError ? (
          <ErrorState
            compact
            message={getErrorMessage(exercisesQuery.error)}
            onRetry={() => void exercisesQuery.refetch()}
            retrying={exercisesQuery.isFetching}
          />
        ) : exercises.length === 0 ? (
          <EmptyState compact title="Aucun exercice trouvé." message="Essaie un autre mot-clé." />
        ) : (
          <ul className="space-y-1.5">
            {exercises.map((exercise) => (
              <li key={exercise.id}>
                <button
                  type="button"
                  onClick={() => onPick(exercise)}
                  className="flex min-h-12 w-full items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/20"
                >
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-medium text-slate-900">{exercise.name}</span>
                    <span className="block truncate text-xs text-slate-500">
                      {[
                        labelFor(EXERCISE_CATEGORY_LABELS, exercise.category),
                        labelFor(MUSCLE_GROUP_LABELS, exercise.muscle_group),
                        labelFor(MATERIEL_LABELS, exercise.equipment),
                      ]
                        .filter(Boolean)
                        .join(" · ")}
                    </span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Modal>
  );
}

export default ExercisePickerModal;
