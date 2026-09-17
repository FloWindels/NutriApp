/**
 * TanStack Query keys. Root segments are stable strings so a whole family can be
 * invalidated at once (`queryClient.invalidateQueries({ queryKey: ['meals'] })`).
 */

type Params = Record<string, unknown> | undefined;

export const queryKeys = {
  me: ["me"] as const,

  profile: ["profile"] as const,
  profilePreview: (input: Params) => ["profile", "preview", input] as const,
  weights: (params?: Params) => ["weights", params ?? {}] as const,

  portions: ["portions"] as const,
  foods: {
    all: ["foods"] as const,
    search: (params: Params) => ["foods", "search", params ?? {}] as const,
    barcode: (ean: string) => ["foods", "barcode", ean] as const,
    detail: (id: number | string) => ["foods", "detail", String(id)] as const,
    favorites: ["foods", "favorites"] as const,
  },

  recipes: {
    all: ["recipes"] as const,
    list: (params?: Params) => ["recipes", "list", params ?? {}] as const,
    detail: (id: number | string) => ["recipes", "detail", String(id)] as const,
  },

  stocks: {
    all: ["stocks"] as const,
    list: (params?: Params) => ["stocks", "list", params ?? {}] as const,
    alerts: ["stocks", "alerts"] as const,
  },

  meals: {
    all: ["meals"] as const,
    day: (date: string) => ["meals", "day", date] as const,
    history: (params?: Params) => ["meals", "history", params ?? {}] as const,
    frequent: ["meals", "frequent"] as const,
  },

  dashboard: {
    all: ["dashboard"] as const,
    day: (date?: string) => ["dashboard", date ?? "today"] as const,
  },
  history: (params?: Params) => ["history", params ?? {}] as const,

  recommendations: {
    all: ["recommendations"] as const,
    day: (date?: string, all?: boolean) => ["recommendations", date ?? "today", all ?? false] as const,
  },

  diets: {
    all: ["diets"] as const,
    catalog: ["diets", "catalog"] as const,
    detail: (key: string) => ["diets", "detail", key] as const,
    evaluate: (days?: number) => ["diets", "evaluate", days ?? 7] as const,
  },

  household: {
    all: ["household"] as const,
    current: ["household", "current"] as const,
    preview: (code: string) => ["household", "preview", code] as const,
    commonMealPreview: (input: Params) => ["household", "common-meal", input] as const,
  },

  shopping: {
    all: ["shopping"] as const,
    list: ["shopping", "list"] as const,
  },

  planner: {
    all: ["planner"] as const,
    week: (weekStart?: string) => ["planner", "week", weekStart ?? "current"] as const,
  },

  sport: {
    all: ["sport"] as const,
    config: ["sport", "config"] as const,
    sports: (params?: Params) => ["sport", "sports", params ?? {}] as const,
    calendar: (params?: Params) => ["sport", "calendar", params ?? {}] as const,
    summary: (date?: string) => ["sport", "summary", date ?? "today"] as const,
    sessions: (params?: Params) => ["sport", "sessions", params ?? {}] as const,
    session: (id: number | string) => ["sport", "session", String(id)] as const,
    exercises: (params?: Params) => ["sport", "exercises", params ?? {}] as const,
  },

  settings: ["settings"] as const,
  notifications: ["notifications"] as const,
  account: {
    export: ["account", "export"] as const,
  },
} as const;

/** Families touched by a meal mutation (add/edit/delete item, copy, consume). */
export const MEAL_MUTATION_KEYS = [
  queryKeys.meals.all,
  queryKeys.dashboard.all,
  queryKeys.recommendations.all,
  queryKeys.history(),
] as const;
