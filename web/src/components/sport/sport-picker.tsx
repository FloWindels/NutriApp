"use client";

import { useEffect, useMemo, useState, type FormEvent } from "react";
import { useMutation } from "@tanstack/react-query";
import { sportApi, useSports } from "@/components/sport/sport-api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Field, SelectField } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { SkeletonList } from "@/components/ui/skeleton";
import { getErrorMessage, isApiError } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { formatNumber } from "@/lib/format";
import type { Sport, SportCategory } from "@/lib/types/api";
import { SPORT_CATEGORY_LABELS } from "@/lib/vocab";
import { useResetOnChange } from "@/lib/use-reset-on-change";

/** Searchable sport catalogue grouped by category, with a « Créer « … » » row. */

const CATEGORY_ORDER = Object.keys(SPORT_CATEGORY_LABELS) as SportCategory[];

export type SportPickerProps = {
  open: boolean;
  onClose: () => void;
  onPick: (sport: Sport) => void;
  title?: string;
};

export function SportPickerModal({ open, onClose, onPick, title = "Choisir un sport" }: SportPickerProps) {
  const [query, setQuery] = useState("");
  const [debounced, setDebounced] = useState("");
  const [creating, setCreating] = useState(false);

  useResetOnChange(String(open), () => {
    if (!open) {
      setQuery("");
      setDebounced("");
      setCreating(false);
    }
  });

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(query.trim()), 250);
    return () => clearTimeout(timer);
  }, [query]);

  const sportsQuery = useSports(debounced ? { q: debounced } : {});
  const sports = useMemo(() => sportsQuery.data ?? [], [sportsQuery.data]);

  const grouped = useMemo(() => {
    const map = new Map<SportCategory, Sport[]>();
    for (const sport of sports) {
      const list = map.get(sport.category) ?? [];
      list.push(sport);
      map.set(sport.category, list);
    }
    return CATEGORY_ORDER.filter((category) => map.has(category)).map((category) => ({
      category,
      items: (map.get(category) ?? []).sort((a, b) => a.name.localeCompare(b.name, "fr")),
    }));
  }, [sports]);

  const trimmed = query.trim();
  const exactMatch = sports.some((sport) => sport.name.toLowerCase() === trimmed.toLowerCase());
  const canCreate = trimmed.length >= 2 && !exactMatch;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={creating ? "Créer un sport" : title}
      description={
        creating
          ? "Il sera visible uniquement par toi."
          : "Cherche ton sport dans le catalogue ou crée celui qui manque."
      }
      size="lg"
    >
      {creating ? (
        <CreateSportForm
          initialName={trimmed}
          onCancel={() => setCreating(false)}
          onCreated={(sport) => {
            setCreating(false);
            onPick(sport);
          }}
        />
      ) : (
        <div className="space-y-4">
          <Field
            label="Rechercher un sport"
            type="search"
            placeholder="Course à pied, natation, padel…"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            autoComplete="off"
          />

          {sportsQuery.isPending ? (
            <SkeletonList rows={5} />
          ) : sportsQuery.isError ? (
            <ErrorState
              compact
              message={getErrorMessage(sportsQuery.error)}
              onRetry={() => void sportsQuery.refetch()}
              retrying={sportsQuery.isFetching}
            />
          ) : (
            <div className="space-y-5">
              {canCreate ? (
                <button
                  type="button"
                  onClick={() => setCreating(true)}
                  className="flex min-h-12 w-full items-center gap-3 rounded-2xl border border-dashed border-emerald-300 bg-emerald-50/60 px-4 py-3 text-left transition hover:border-emerald-500 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/20"
                >
                  <span
                    aria-hidden="true"
                    className="grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-white text-emerald-700"
                  >
                    +
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-semibold text-emerald-900">
                      {`Créer « ${trimmed} »`}
                    </span>
                    <span className="block text-xs text-emerald-800/80">
                      Ajoute un sport qui manque au catalogue
                    </span>
                  </span>
                </button>
              ) : null}

              {grouped.length === 0 ? (
                <EmptyState
                  compact
                  title="Aucun sport disponible pour le moment."
                  message="Essaie un autre mot ou crée ton sport."
                />
              ) : (
                grouped.map((group) => (
                  <section key={group.category}>
                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                      {SPORT_CATEGORY_LABELS[group.category] ?? group.category}
                    </h3>
                    <ul className="space-y-1.5">
                      {group.items.map((sport) => (
                        <li key={sport.id}>
                          <button
                            type="button"
                            onClick={() => onPick(sport)}
                            className={cn(
                              "flex min-h-12 w-full items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/20",
                            )}
                          >
                            <span className="min-w-0">
                              <span className="block truncate text-sm font-medium text-slate-900">
                                {sport.name}
                              </span>
                              <span className="block text-xs text-slate-500">
                                {`MET ${formatNumber(sport.met_moderee)} · modérée`}
                                {sport.is_mine ? " · mon sport" : ""}
                              </span>
                            </span>
                          </button>
                        </li>
                      ))}
                    </ul>
                  </section>
                ))
              )}
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}

function CreateSportForm({
  initialName,
  onCancel,
  onCreated,
}: {
  initialName: string;
  onCancel: () => void;
  onCreated: (sport: Sport) => void;
}) {
  const [name, setName] = useState(initialName);
  const [category, setCategory] = useState<SportCategory>("autre");
  const [met, setMet] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () =>
      sportApi.createSport({
        name: name.trim(),
        category,
        ...(met.trim() ? { met_moderee: Number(met.replace(",", ".")) } : {}),
      }),
    onSuccess: (response) => onCreated(response.data),
    onError: (error) => {
      if (isApiError(error) && error.isValidation) {
        const next: Record<string, string> = {};
        for (const field of ["name", "category", "met_moderee"]) {
          const message = error.fieldError(field);
          if (message) next[field] = message;
        }
        setErrors(next);
        if (Object.keys(next).length === 0) setBanner(error.message);
        return;
      }
      setBanner(getErrorMessage(error));
    },
  });

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBanner(null);
    setErrors({});
    if (name.trim().length < 2) {
      setErrors({ name: "Donne un nom d’au moins 2 caractères." });
      return;
    }
    mutation.mutate();
  }

  return (
    <form onSubmit={submit} className="space-y-4" noValidate>
      {banner ? <Banner tone="error">{banner}</Banner> : null}
      <Field
        label="Nom du sport"
        required
        value={name}
        onChange={(event) => setName(event.target.value)}
        error={errors.name}
        maxLength={80}
      />
      <SelectField
        label="Catégorie"
        value={category}
        onChange={(event) => setCategory(event.target.value as SportCategory)}
        error={errors.category}
      >
        {CATEGORY_ORDER.map((key) => (
          <option key={key} value={key}>
            {SPORT_CATEGORY_LABELS[key]}
          </option>
        ))}
      </SelectField>
      <Field
        label="MET (optionnel)"
        inputMode="decimal"
        placeholder="6"
        value={met}
        onChange={(event) => setMet(event.target.value)}
        error={errors.met_moderee}
        hint="Intensité modérée, entre 1,5 et 15. Laisse vide si tu ne sais pas."
      />
      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="secondary" onClick={onCancel} disabled={mutation.isPending}>
          Annuler
        </Button>
        <Button type="submit" loading={mutation.isPending}>
          Créer le sport
        </Button>
      </div>
    </form>
  );
}

export default SportPickerModal;
