"use client";

import { PlanTile } from "@/components/planner/plan-tile";
import { cn } from "@/lib/cn";
import { capitalize, formatDay, formatKcal, todayIso } from "@/lib/format";
import { MEAL_TYPES, type MealPlan, type MealType, type Planner } from "@/lib/types/api";
import { MEAL_TYPE_LABELS, labelFor } from "@/lib/vocab";

export type WeekGridProps = {
  planner: Planner;
  onAdd: (date: string, mealType: MealType) => void;
  onLog: (plan: MealPlan) => void;
  onEdit: (plan: MealPlan) => void;
  onDelete: (plan: MealPlan) => void;
};

function AddSlotButton({
  date,
  mealType,
  onAdd,
}: {
  date: string;
  mealType: MealType;
  onAdd: (date: string, mealType: MealType) => void;
}) {
  const label = `Ajouter un repas · ${labelFor(MEAL_TYPE_LABELS, mealType)} · ${formatDay(date)}`;
  return (
    <button
      type="button"
      onClick={() => onAdd(date, mealType)}
      title={label}
      aria-label={label}
      className="flex h-10 w-full items-center justify-center gap-1.5 rounded-2xl border border-dashed border-slate-300 bg-white/60 text-sm font-medium text-slate-500 transition hover:border-emerald-400 hover:bg-emerald-50 hover:text-emerald-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-600/20"
    >
      <span aria-hidden="true" className="text-lg leading-none">
        +
      </span>
      <span className="hidden xl:inline">Ajouter</span>
    </button>
  );
}

/**
 * Seven day columns × four meal rows on desktop (a real table: days as column
 * headers, meals as row headers, totals in the footer) and a stacked list of
 * days on small screens.
 */
export function WeekGrid({ planner, onAdd, onLog, onEdit, onDelete }: WeekGridProps) {
  const today = todayIso();
  const totals = new Map(planner.totals_per_day?.map((entry) => [entry.date, entry.calories]) ?? []);

  return (
    <>
      {/* Desktop: 7 columns × 4 meal rows */}
      <div className="hidden overflow-x-auto rounded-[1.75rem] border border-slate-200 bg-white lg:block">
        <table className="w-full min-w-[980px] border-collapse text-left">
          <caption className="sr-only">
            Repas prévus de la semaine du {formatDay(planner.week_start)}, par jour et par type de repas.
          </caption>
          <thead>
            <tr>
              <th scope="col" className="w-[110px] border-b border-slate-200 px-3 py-3 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                Repas
              </th>
              {planner.days.map((day) => (
                <th
                  key={day.date}
                  scope="col"
                  className={cn(
                    "border-b border-l border-slate-200 px-3 py-3 text-sm font-semibold",
                    day.date === today ? "bg-emerald-50 text-emerald-900" : "text-slate-900",
                  )}
                >
                  <span className="block">{capitalize(formatDay(day.date))}</span>
                  {day.date === today ? (
                    <span className="mt-0.5 block text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-700">
                      Aujourd’hui
                    </span>
                  ) : null}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {MEAL_TYPES.map((mealType) => (
              <tr key={mealType} className="align-top">
                <th
                  scope="row"
                  className="border-b border-slate-100 px-3 py-3 text-sm font-semibold text-slate-700"
                >
                  {labelFor(MEAL_TYPE_LABELS, mealType)}
                </th>
                {planner.days.map((day) => {
                  const plans = day.slots?.[mealType] ?? [];
                  return (
                    <td
                      key={`${day.date}-${mealType}`}
                      className={cn(
                        "border-b border-l border-slate-100 p-2",
                        day.date === today && "bg-emerald-50/40",
                      )}
                    >
                      <div className="space-y-2">
                        {plans.map((plan) => (
                          <PlanTile
                            key={plan.id}
                            plan={plan}
                            onLog={onLog}
                            onEdit={onEdit}
                            onDelete={onDelete}
                          />
                        ))}
                        <AddSlotButton date={day.date} mealType={mealType} onAdd={onAdd} />
                      </div>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="bg-slate-50">
              <th scope="row" className="px-3 py-3 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                Total prévu
              </th>
              {planner.days.map((day) => (
                <td
                  key={`total-${day.date}`}
                  className="border-l border-slate-200 px-3 py-3 text-sm font-semibold text-slate-800"
                >
                  {formatKcal(totals.get(day.date) ?? 0)}
                </td>
              ))}
            </tr>
          </tfoot>
        </table>
      </div>

      {/* Mobile / tablet: one card per day */}
      <div className="space-y-4 lg:hidden">
        {planner.days.map((day) => (
          <section
            key={day.date}
            className={cn(
              "rounded-[1.75rem] border p-4",
              day.date === today ? "border-emerald-200 bg-emerald-50/50" : "border-slate-200 bg-white",
            )}
            aria-label={capitalize(formatDay(day.date))}
          >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <h3 className="text-base font-semibold text-slate-900">
                {capitalize(formatDay(day.date))}
                {day.date === today ? (
                  <span className="ml-2 text-xs font-semibold uppercase tracking-[0.14em] text-emerald-700">
                    Aujourd’hui
                  </span>
                ) : null}
              </h3>
              <p className="text-sm font-semibold text-slate-700">
                Total prévu {formatKcal(totals.get(day.date) ?? 0)}
              </p>
            </div>

            <div className="mt-3 space-y-4">
              {MEAL_TYPES.map((mealType) => {
                const plans = day.slots?.[mealType] ?? [];
                return (
                  <div key={`${day.date}-${mealType}`}>
                    <p className="mb-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                      {labelFor(MEAL_TYPE_LABELS, mealType)}
                    </p>
                    <div className="space-y-2">
                      {plans.map((plan) => (
                        <PlanTile key={plan.id} plan={plan} onLog={onLog} onEdit={onEdit} onDelete={onDelete} />
                      ))}
                      <AddSlotButton date={day.date} mealType={mealType} onAdd={onAdd} />
                    </div>
                  </div>
                );
              })}
            </div>
          </section>
        ))}
      </div>
    </>
  );
}

export default WeekGrid;
