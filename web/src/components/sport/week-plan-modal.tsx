"use client";

import { useState } from "react";
import { useMutation } from "@tanstack/react-query";
import { Chip, ChipGroup, Switch } from "@/components/sport/chips";
import { sportApi, useInvalidateSport, useSportConfig } from "@/components/sport/sport-api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Modal } from "@/components/ui/modal";
import { useToast } from "@/components/ui/toast";
import { getErrorMessage } from "@/lib/api-client";
import { addDays, formatDay, startOfWeekMonday } from "@/lib/format";
import { WEEKDAY_LABELS, WEEKDAY_SHORT } from "@/lib/vocab";
import { useResetOnChange } from "@/lib/use-reset-on-change";

/** « Planifier ma semaine » — `POST /sport/calendar/plan-week`. */

export type WeekPlanModalProps = {
  open: boolean;
  onClose: () => void;
  /** Any date inside the week to plan. */
  date: string;
};

export function WeekPlanModal({ open, onClose, date }: WeekPlanModalProps) {
  const config = useSportConfig();
  const invalidate = useInvalidateSport();
  const { success } = useToast();
  const [days, setDays] = useState<number[]>([1, 3, 5]);
  const [withAi, setWithAi] = useState(true);
  const [replace, setReplace] = useState(false);
  const [banner, setBanner] = useState<string | null>(null);

  const weekStart = startOfWeekMonday(date);
  const weekEnd = addDays(weekStart, 6);
  const iaAvailable = config.data?.ia_disponible ?? false;

  useResetOnChange(String(open), () => {
    if (!open) return;
    setDays([1, 3, 5]);
    setWithAi(true);
    setReplace(false);
    setBanner(null);
  });

  const mutation = useMutation({
    mutationFn: () =>
      sportApi.planWeek({
        week_start: weekStart,
        ...(days.length > 0 ? { days: [...days].sort((a, b) => a - b) } : {}),
        mode: iaAvailable && withAi ? "ia" : "regles",
        replace,
      }),
    onSuccess: async (response) => {
      await invalidate();
      success(
        "Ta semaine est planifiée.",
        response.generated_by === "ia" ? "Proposée par l’IA" : "Règles Mavi’oh",
      );
      onClose();
    },
    onError: (error) => setBanner(getErrorMessage(error)),
  });

  function toggleDay(weekday: number) {
    setDays((current) =>
      current.includes(weekday) ? current.filter((day) => day !== weekday) : [...current, weekday],
    );
  }

  return (
    <Modal
      open={open}
      onClose={mutation.isPending ? () => undefined : onClose}
      locked={mutation.isPending}
      title="Planifier ma semaine"
      description={`Du ${formatDay(weekStart)} au ${formatDay(weekEnd)}`}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Annuler
          </Button>
          <Button onClick={() => mutation.mutate()} loading={mutation.isPending}>
            Planifier ma semaine
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        {banner ? <Banner tone="error">{banner}</Banner> : null}

        {mutation.isPending ? (
          <Banner tone="info" title="Le coach prépare ta semaine…">
            Cela peut prendre 20 à 60 secondes.
          </Banner>
        ) : null}

        <ChipGroup
          legend="Jours d’entraînement"
          multiple
          hint={
            days.length === 0
              ? "Aucun jour choisi : Mavi’oh répartit tes séances selon ton profil."
              : `${days.length} jour${days.length > 1 ? "s" : ""} sélectionné${days.length > 1 ? "s" : ""}`
          }
        >
          {WEEKDAY_SHORT.map((initial, index) => {
            const weekday = index + 1;
            return (
              <Chip
                key={WEEKDAY_LABELS[index]}
                selected={days.includes(weekday)}
                onClick={() => toggleDay(weekday)}
                title={WEEKDAY_LABELS[index]}
                className="w-12 px-0"
              >
                <span className="sr-only">{WEEKDAY_LABELS[index]}</span>
                <span aria-hidden="true">{initial}</span>
              </Chip>
            );
          })}
        </ChipGroup>

        {iaAvailable ? (
          <Switch
            checked={withAi}
            onChange={setWithAi}
            label="Avec l’IA"
            hint="Le coach Mavi’oh répartit tes séances selon ton objectif et ton historique."
          />
        ) : null}

        <Switch
          checked={replace}
          onChange={setReplace}
          label="Remplacer"
          hint="Efface les séances déjà prévues cette semaine avant d’ajouter les nouvelles."
        />
      </div>
    </Modal>
  );
}

export default WeekPlanModal;
