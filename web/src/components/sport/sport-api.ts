"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useCallback, useMemo } from "react";
import { apiDelete, apiGet, apiPost, apiPut, type ApiFetchInit } from "@/lib/api-client";
import { queryKeys } from "@/lib/query-keys";
import type {
  ActivityInput,
  ActivityResponse,
  CaloriesEstimate,
  CaloriesEstimateInput,
  DataEnvelope,
  Exercise,
  ExerciseSearchParams,
  GenerateSessionInput,
  ListEnvelope,
  MessageEnvelope,
  PaginatedEnvelope,
  Profile,
  SessionCompleteInput,
  SessionCompleteResponse,
  SessionInput,
  SessionSearchParams,
  SessionUpdateInput,
  Sport,
  SportCalendar,
  SportConfig,
  SportInput,
  SportPlan,
  SportPlanInput,
  SportPlanLogInput,
  SportPlanLogResponse,
  SportPlanUpdateInput,
  SportPlanWeekInput,
  SportPlanWeekResponse,
  SportRecurringInput,
  SportRecurringResponse,
  SportSummary,
  VocabEntry,
  WorkoutProposal,
  WorkoutSession,
} from "@/lib/types/api";
import {
  FOCUS_LABELS,
  INTENSITY_LABELS,
  MATERIEL_LABELS,
  SPORT_CATEGORY_LABELS,
  SPORT_LIEU_LABELS,
  SPORT_NIVEAU_LABELS,
  SPORT_OBJECTIF_LABELS,
  ZONE_LABELS,
  optionsFrom,
} from "@/lib/vocab";

/* ------------------------------------------------------------------ */
/* Typed endpoint wrappers (paths relative to /api)                     */
/* ------------------------------------------------------------------ */

export const sportApi = {
  config: () => apiGet<DataEnvelope<SportConfig>>("/sport/config"),
  summary: (date?: string) => apiGet<DataEnvelope<SportSummary>>("/sport/summary", { date }),
  calendar: (from: string, to: string) =>
    apiGet<DataEnvelope<SportCalendar>>("/sport/calendar", { from, to }),
  sports: (q?: string, category?: string) =>
    apiGet<ListEnvelope<Sport>>("/sport/sports", { q, category }),
  createSport: (input: SportInput) => apiPost<DataEnvelope<Sport>>("/sport/sports", input),
  exercises: (params: ExerciseSearchParams) =>
    apiGet<PaginatedEnvelope<Exercise>>("/sport/exercises", { ...params }),
  profile: () => apiGet<Profile>("/profile"),

  createPlan: (input: SportPlanInput) => apiPost<DataEnvelope<SportPlan>>("/sport/calendar", input),
  createRecurring: (input: SportRecurringInput) =>
    apiPost<SportRecurringResponse>("/sport/calendar/recurring", input),
  updatePlan: (id: number, input: SportPlanUpdateInput) =>
    apiPut<DataEnvelope<SportPlan>>(`/sport/calendar/${id}`, input),
  deletePlan: (id: number, serie = false) =>
    apiDelete<MessageEnvelope>(`/sport/calendar/${id}`, undefined, {
      query: serie ? { serie: 1 } : undefined,
    }),
  logPlan: (id: number, input: SportPlanLogInput) =>
    apiPost<SportPlanLogResponse>(`/sport/calendar/${id}/log`, input),
  proposeForPlan: (id: number, input: Partial<GenerateSessionInput>, init?: ApiFetchInit) =>
    apiPost<DataEnvelope<WorkoutProposal>>(`/sport/calendar/${id}/propose`, input, init),
  planWeek: (input: SportPlanWeekInput, init?: ApiFetchInit) =>
    apiPost<SportPlanWeekResponse>("/sport/calendar/plan-week", input, init),

  logActivity: (input: ActivityInput) => apiPost<ActivityResponse>("/sport/activities", input),
  estimateCalories: (input: CaloriesEstimateInput, init?: ApiFetchInit) =>
    apiPost<DataEnvelope<CaloriesEstimate>>("/sport/calories/estimate", input, init),

  sessions: (params: SessionSearchParams) =>
    apiGet<ListEnvelope<WorkoutSession>>("/sport/sessions", { ...params }),
  session: (id: number | string) => apiGet<DataEnvelope<WorkoutSession>>(`/sport/sessions/${id}`),
  generate: (input: GenerateSessionInput, init?: ApiFetchInit) =>
    apiPost<DataEnvelope<WorkoutProposal>>("/sport/sessions/generate", input, init),
  createSession: (input: SessionInput) => apiPost<DataEnvelope<WorkoutSession>>("/sport/sessions", input),
  updateSession: (id: number, input: SessionUpdateInput) =>
    apiPut<DataEnvelope<WorkoutSession>>(`/sport/sessions/${id}`, input),
  startSession: (id: number) => apiPost<DataEnvelope<WorkoutSession>>(`/sport/sessions/${id}/start`),
  completeSession: (id: number, input: SessionCompleteInput) =>
    apiPost<SessionCompleteResponse>(`/sport/sessions/${id}/complete`, input),
  cancelSession: (id: number) => apiPost<DataEnvelope<WorkoutSession>>(`/sport/sessions/${id}/cancel`),
  deleteSession: (id: number) => apiDelete<MessageEnvelope>(`/sport/sessions/${id}`),
};

/** Families refreshed after any sport mutation (bonus feeds the daily budget). */
export const SPORT_MUTATION_KEYS = [
  queryKeys.sport.all,
  queryKeys.dashboard.all,
  queryKeys.meals.all,
  queryKeys.recommendations.all,
] as const;

export function useInvalidateSport() {
  const queryClient = useQueryClient();
  return useCallback(async () => {
    await Promise.all(
      SPORT_MUTATION_KEYS.map((queryKey) => queryClient.invalidateQueries({ queryKey })),
    );
  }, [queryClient]);
}

/* ------------------------------------------------------------------ */
/* Config & vocab                                                       */
/* ------------------------------------------------------------------ */

export function useSportConfig() {
  return useQuery({
    queryKey: queryKeys.sport.config,
    queryFn: async () => (await sportApi.config()).data,
    staleTime: 10 * 60 * 1000,
  });
}

export type SportVocab = {
  lieux: VocabEntry[];
  zones: VocabEntry[];
  focus: VocabEntry[];
  objectifs: VocabEntry[];
  niveaux: VocabEntry[];
  materiel: VocabEntry[];
  intensites: VocabEntry[];
  categories: VocabEntry[];
  /** Label lookup with a readable fallback. */
  label: (list: VocabEntry[], key: string | null | undefined) => string;
};

const FALLBACK_VOCAB = {
  lieux: optionsFrom(SPORT_LIEU_LABELS),
  zones: optionsFrom(ZONE_LABELS),
  focus: optionsFrom(FOCUS_LABELS),
  objectifs: optionsFrom(SPORT_OBJECTIF_LABELS),
  niveaux: optionsFrom(SPORT_NIVEAU_LABELS),
  materiel: optionsFrom(MATERIEL_LABELS),
  intensites: optionsFrom(INTENSITY_LABELS),
  categories: optionsFrom(SPORT_CATEGORY_LABELS),
};

export function vocabLabel(list: VocabEntry[], key: string | null | undefined): string {
  if (!key) return "";
  return list.find((entry) => entry.key === key)?.label ?? key.replace(/_/g, " ");
}

/** Server vocab from `GET /sport/config`, falling back to `src/lib/vocab`. */
export function useSportVocab(): { vocab: SportVocab; config: SportConfig | undefined; isLoading: boolean } {
  const config = useSportConfig();
  const vocab = useMemo<SportVocab>(() => {
    const server = config.data?.vocab;
    const pick = (serverList: VocabEntry[] | undefined, fallback: VocabEntry[]) =>
      serverList && serverList.length > 0 ? serverList : fallback;
    return {
      lieux: pick(server?.lieux, FALLBACK_VOCAB.lieux),
      zones: pick(server?.zones, FALLBACK_VOCAB.zones),
      focus: pick(server?.focus, FALLBACK_VOCAB.focus),
      objectifs: pick(server?.objectifs, FALLBACK_VOCAB.objectifs),
      niveaux: pick(server?.niveaux, FALLBACK_VOCAB.niveaux),
      materiel: pick(server?.materiel, FALLBACK_VOCAB.materiel),
      intensites: pick(server?.intensites, FALLBACK_VOCAB.intensites),
      categories: pick(server?.categories_sport, FALLBACK_VOCAB.categories),
      label: vocabLabel,
    };
  }, [config.data]);
  return { vocab, config: config.data, isLoading: config.isLoading };
}

/* ------------------------------------------------------------------ */
/* Small helpers                                                        */
/* ------------------------------------------------------------------ */

export const DURATION_CHIPS = [15, 20, 30, 45, 60, 90] as const;
export const PLAN_DURATION_CHIPS = [20, 30, 45, 60, 90] as const;

/** Coefficient may arrive as 0.5 or 50 — always return a 0–100 percentage. */
export function coefficientPct(value: number | null | undefined): number {
  if (value === null || value === undefined || !Number.isFinite(value)) return 100;
  return value <= 1 ? Math.round(value * 100) : Math.round(value);
}

/** ISO weekday 1 = lundi … 7 = dimanche. */
export function isoWeekday(iso: string): 1 | 2 | 3 | 4 | 5 | 6 | 7 {
  const [y, m, d] = iso.split("-").map(Number);
  const day = new Date(y, m - 1, d).getDay();
  return (day === 0 ? 7 : day) as 1 | 2 | 3 | 4 | 5 | 6 | 7;
}

export function monthBounds(iso: string): { from: string; to: string } {
  const [y, m] = iso.split("-").map(Number);
  const first = new Date(y, m - 1, 1);
  const last = new Date(y, m, 0);
  const pad = (n: number) => String(n).padStart(2, "0");
  return {
    from: `${first.getFullYear()}-${pad(first.getMonth() + 1)}-01`,
    to: `${last.getFullYear()}-${pad(last.getMonth() + 1)}-${pad(last.getDate())}`,
  };
}

/** « 3 × 12 » / « 45 s » / « 2,5 km » description of an exercise row. */
export function describeSets(row: {
  sets?: number | null;
  reps?: number | null;
  duration_sec?: number | null;
  distance_km?: number | null;
}): string {
  const parts: string[] = [];
  if (row.sets && row.reps) parts.push(`${row.sets} × ${row.reps}`);
  else if (row.reps) parts.push(`${row.reps} rép.`);
  else if (row.sets) parts.push(`${row.sets} séries`);
  if (row.duration_sec) {
    const s = row.duration_sec;
    parts.push(s >= 60 ? `${Math.floor(s / 60)} min${s % 60 ? ` ${s % 60} s` : ""}` : `${s} s`);
  }
  if (row.distance_km) parts.push(`${String(row.distance_km).replace(".", ",")} km`);
  return parts.join(" · ");
}

/* ------------------------------------------------------------------ */
/* Query hooks                                                          */
/* ------------------------------------------------------------------ */

/** Catalogue (public sports + the user's own), debounced by the caller. */
export function useSports(params: { q?: string; category?: string } = {}) {
  return useQuery({
    queryKey: queryKeys.sport.sports(params),
    queryFn: async () => (await sportApi.sports(params.q, params.category)).data,
    staleTime: 5 * 60 * 1000,
  });
}

export function useSportSummary(date?: string) {
  return useQuery({
    queryKey: queryKeys.sport.summary(date),
    queryFn: async () => (await sportApi.summary(date)).data,
  });
}

export function useSportCalendar(from: string, to: string) {
  return useQuery({
    queryKey: queryKeys.sport.calendar({ from, to }),
    queryFn: async () => (await sportApi.calendar(from, to)).data,
  });
}

export function useSportSessions(params: SessionSearchParams) {
  return useQuery({
    queryKey: queryKeys.sport.sessions(params),
    queryFn: async () => (await sportApi.sessions(params)).data,
  });
}

export function useSportSession(id: number | string) {
  return useQuery({
    queryKey: queryKeys.sport.session(id),
    queryFn: async () => (await sportApi.session(id)).data,
  });
}

/** `GET /profile` — used to prefill the generation form. */
export function useSportProfile() {
  return useQuery({
    queryKey: queryKeys.profile,
    queryFn: () => sportApi.profile(),
    staleTime: 5 * 60 * 1000,
  });
}
