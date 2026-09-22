"use client";

import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { CheckboxField } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { apiPost, getErrorMessage } from "@/lib/api-client";
import { capitalize, formatDay, formatNumber } from "@/lib/format";
import { messages } from "@/lib/messages";
import type { MealPlan, MealPlanLogResponse } from "@/lib/types/api";
import { MEAL_TYPE_LABELS, labelFor } from "@/lib/vocab";
import { useResetOnChange } from "@/lib/use-reset-on-change";

export type LogPlanModalProps = {
  open: boolean;
  onClose: () => void;
  plan: MealPlan | null;
  onLogged: (plan: MealPlan, decrementStock: boolean) => void;
};

/**
 * « Réaliser » — turns a planned meal into a real meal
 * (`POST /planner/{id}/log`) with an optional stock decrement.
 */
export function LogPlanModal({ open, onClose, plan, onLogged }: LogPlanModalProps) {
  const [decrementStock, setDecrementStock] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useResetOnChange(`${open}:${plan?.id ?? ""}`, () => {
    if (!open) return;
    setDecrementStock(true);
    setError(null);
  });

  const log = useMutation({
    mutationFn: (target: MealPlan) =>
      apiPost<MealPlanLogResponse>(`/planner/${target.id}/log`, { decrement_stock: decrementStock }),
    onSuccess: (_response, target) => {
      onLogged(target, decrementStock);
      onClose();
    },
    onError: (err) => setError(getErrorMessage(err)),
  });

  if (!plan) return null;

  return (
    <Modal
      open={open}
      onClose={onClose}
      locked={log.isPending}
      title="Réaliser ce repas"
      description={`${plan.title} · ${labelFor(MEAL_TYPE_LABELS, plan.meal_type)} · ${capitalize(formatDay(plan.date))}`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={log.isPending}>
            {messages.cancel}
          </Button>
          <Button onClick={() => log.mutate(plan)} loading={log.isPending}>
            Enregistrer le repas
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error ? <Banner tone="error">{error}</Banner> : null}
        <p className="text-sm leading-6 text-slate-600">
          Ce repas sera ajouté à ta journée du {formatDay(plan.date)} pour{" "}
          {formatNumber(plan.servings, 1)} {plan.servings > 1 ? "portions" : "portion"}.
        </p>
        <CheckboxField
          label={messages.removeFromStock}
          hint="Les ingrédients disponibles seront décomptés de ton stock."
          checked={decrementStock}
          disabled={log.isPending}
          onChange={(event) => setDecrementStock(event.target.checked)}
        />
      </div>
    </Modal>
  );
}

export default LogPlanModal;
