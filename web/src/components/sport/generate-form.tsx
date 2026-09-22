"use client";

import { useEffect, useRef, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Chip, ChipGroup, Switch } from "@/components/sport/chips";
import { ProposalView } from "@/components/sport/proposal-view";
import {
  DURATION_CHIPS,
  sportApi,
  useSportProfile,
  useSportVocab,
} from "@/components/sport/sport-api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { CheckboxField, Field, TextareaField } from "@/components/ui/field";
import { getErrorMessage, isApiError } from "@/lib/api-client";
import { messages } from "@/lib/messages";
import type {
  GenerateSessionInput,
  Materiel,
  SportFocus,
  SportLieu,
  SportNiveau,
  SportObjectif,
  SportType,
  WorkoutProposal,
  ZoneAEviter,
} from "@/lib/types/api";
import { SPORT_TYPE_LABELS, optionsFrom } from "@/lib/vocab";

/**
 * « Générer une séance » — the full generation form, its progress state and the
 * resulting proposal (addendum §C.4 and §D « GenerateSheet v3 »).
 */

const schema = z.object({
  duration_min: z
    .number({ error: "Indique une durée entre 10 et 180 minutes." })
    .int()
    .min(10, "Indique une durée entre 10 et 180 minutes.")
    .max(180, "Indique une durée entre 10 et 180 minutes."),
  notes: z.string().max(500, "500 caractères maximum.").optional(),
});

type FormValues = z.infer<typeof schema>;

/** A public gym comes with the usual machines (addendum §D). */
const GYM_EQUIPMENT: Materiel[] = ["machine", "barre", "halteres", "banc"];

const SPORT_TYPE_OPTIONS = optionsFrom(SPORT_TYPE_LABELS);

export type GenerateFormProps = {
  /** When set, generation runs through `POST /sport/calendar/{id}/propose`. */
  planId?: number | null;
  /** Date the saved session should land on. */
  date?: string;
  defaultTime?: string | null;
  defaultDuration?: number;
  onClose?: () => void;
};

export function GenerateForm({
  planId = null,
  date,
  defaultTime,
  defaultDuration,
  onClose,
}: GenerateFormProps) {
  const { vocab, config } = useSportVocab();
  const profileQuery = useSportProfile();
  const profile = profileQuery.data;

  const [withAi, setWithAi] = useState(true);
  const [sportType, setSportType] = useState<SportType | null>(null);
  const [lieu, setLieu] = useState<SportLieu | null>(null);
  const [goal, setGoal] = useState<SportObjectif | null>(null);
  const [level, setLevel] = useState<SportNiveau | null>(null);
  const [equipment, setEquipment] = useState<Materiel[]>([]);
  const [focus, setFocus] = useState<SportFocus[]>([]);
  const [zones, setZones] = useState<ZoneAEviter[]>([]);
  const [prefilled, setPrefilled] = useState(false);

  const [proposal, setProposal] = useState<WorkoutProposal | null>(null);
  const [pending, setPending] = useState(false);
  const [banner, setBanner] = useState<string | null>(null);
  const controllerRef = useRef<AbortController | null>(null);

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    setError,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { duration_min: defaultDuration ?? 45, notes: "" },
  });

  /* Prefill from the profile once it arrives. */
  useEffect(() => {
    if (!profile || prefilled) return;
    setPrefilled(true);
    setLieu(profile.sport_lieu ?? null);
    setGoal(profile.sport_objectif ?? null);
    setLevel(profile.sport_niveau ?? null);
    setEquipment(profile.sport_materiel ?? []);
    setFocus(profile.sport_focus ?? []);
    setZones(profile.sport_zones_a_eviter ?? []);
    if (profile.sport_notes) setValue("notes", profile.sport_notes);
    if (!defaultDuration && profile.sport_temps_dispo_min) {
      setValue("duration_min", Math.min(180, Math.max(10, profile.sport_temps_dispo_min)));
    }
  }, [profile, prefilled, defaultDuration, setValue]);

  useEffect(() => () => controllerRef.current?.abort(), []);

  const duration = Number(watch("duration_min")) || 0;
  const iaAvailable = config?.ia_disponible ?? false;

  function toggleEquipment(item: Materiel) {
    setEquipment((current) => {
      if (item === "aucun") return current.includes("aucun") ? [] : ["aucun"];
      const without = current.filter((value) => value !== "aucun");
      return without.includes(item) ? without.filter((value) => value !== item) : [...without, item];
    });
  }

  function pickLieu(next: SportLieu) {
    setLieu(next);
    if (next === "salle_publique") {
      setEquipment((current) => {
        const merged = new Set<Materiel>(current.filter((value) => value !== "aucun"));
        GYM_EQUIPMENT.forEach((item) => merged.add(item));
        return [...merged];
      });
    }
  }

  function toggleIn<T extends string>(list: T[], setList: (next: T[]) => void, value: T) {
    setList(list.includes(value) ? list.filter((item) => item !== value) : [...list, value]);
  }

  async function generate(values: FormValues) {
    controllerRef.current?.abort();
    const controller = new AbortController();
    controllerRef.current = controller;
    setPending(true);
    setBanner(null);
    try {
      const payload: GenerateSessionInput = {
        mode: iaAvailable && withAi ? "ia" : "regles",
        duration_min: values.duration_min,
        ...(sportType ? { sport_type: sportType } : {}),
        ...(lieu ? { lieu } : {}),
        ...(goal ? { goal } : {}),
        ...(level ? { level } : {}),
        equipment,
        focus,
        zones_a_eviter: zones,
        ...(values.notes?.trim() ? { notes: values.notes.trim() } : {}),
      };
      const response = planId
        ? await sportApi.proposeForPlan(planId, payload, { signal: controller.signal })
        : await sportApi.generate(payload, { signal: controller.signal });
      if (controller.signal.aborted) return;
      setProposal(response.data);
    } catch (error) {
      if (controller.signal.aborted) return;
      if (isApiError(error) && error.isValidation) {
        const message = error.fieldError("duration_min");
        if (message) {
          setError("duration_min", { message });
          return;
        }
      }
      setBanner(getErrorMessage(error));
    } finally {
      if (!controller.signal.aborted) setPending(false);
      if (controllerRef.current === controller) controllerRef.current = null;
    }
  }

  function cancelGeneration() {
    controllerRef.current?.abort();
    controllerRef.current = null;
    setPending(false);
  }

  if (proposal) {
    return (
      <ProposalView
        proposal={proposal}
        onProposalChange={setProposal}
        onRegenerate={() => {
          setProposal(null);
          void handleSubmit(generate)();
        }}
        regenerating={pending}
        onBack={() => setProposal(null)}
        sportPlanId={planId}
        defaultDate={date}
        defaultTime={defaultTime}
      />
    );
  }

  return (
    <Card>
      <CardHeader
        title="Générer une séance"
        subtitle="Le coach adapte les exercices à ton objectif, ton matériel et tes zones sensibles."
        actions={
          onClose ? (
            <Button variant="ghost" size="sm" onClick={onClose} disabled={pending}>
              Fermer
            </Button>
          ) : null
        }
      />

      {pending ? (
        <div className="mt-4 space-y-3">
          <Banner tone="info" title="Le coach prépare ta séance…">
            Cela peut prendre 20 à 60 secondes.
          </Banner>
          <Button variant="secondary" onClick={cancelGeneration}>
            Annuler la génération
          </Button>
        </div>
      ) : null}

      <form className="mt-4 space-y-5" noValidate onSubmit={handleSubmit(generate)}>
        {banner ? <Banner tone="error">{banner}</Banner> : null}

        {iaAvailable ? (
          <Switch
            checked={withAi}
            onChange={setWithAi}
            label="Avec l’IA"
            hint={
              config?.llm_model
                ? `Le coach Mavi’oh adapte la séance à ton contexte (${config.llm_model}).`
                : "Le coach Mavi’oh adapte la séance à ton contexte."
            }
          />
        ) : null}

        <ChipGroup legend="Type de sport">
          {SPORT_TYPE_OPTIONS.map((entry) => (
            <Chip
              key={entry.key}
              role="radio"
              selected={sportType === entry.key}
              onClick={() => setSportType(sportType === entry.key ? null : entry.key)}
            >
              {entry.label}
            </Chip>
          ))}
        </ChipGroup>

        <ChipGroup legend="Lieu">
          {vocab.lieux.map((entry) => (
            <Chip
              key={entry.key}
              role="radio"
              selected={lieu === entry.key}
              onClick={() => pickLieu(entry.key as SportLieu)}
            >
              {entry.label}
            </Chip>
          ))}
        </ChipGroup>

        <div className="grid gap-5 lg:grid-cols-2">
          <ChipGroup legend="Objectif">
            {vocab.objectifs.map((entry) => (
              <Chip
                key={entry.key}
                role="radio"
                selected={goal === entry.key}
                onClick={() => setGoal(entry.key as SportObjectif)}
              >
                {entry.label}
              </Chip>
            ))}
          </ChipGroup>

          <ChipGroup legend="Niveau">
            {vocab.niveaux.map((entry) => (
              <Chip
                key={entry.key}
                role="radio"
                selected={level === entry.key}
                onClick={() => setLevel(entry.key as SportNiveau)}
              >
                {entry.label}
              </Chip>
            ))}
          </ChipGroup>
        </div>

        <ChipGroup legend="Durée">
          {DURATION_CHIPS.map((value) => (
            <Chip
              key={value}
              role="radio"
              selected={duration === value}
              onClick={() => setValue("duration_min", value, { shouldValidate: true })}
            >
              {`${value} min`}
            </Chip>
          ))}
        </ChipGroup>

        <Field
          label="Durée (min)"
          type="number"
          min={10}
          max={180}
          required
          wrapperClassName="max-w-xs"
          error={errors.duration_min?.message}
          {...register("duration_min", { valueAsNumber: true })}
        />

        <fieldset className="min-w-0">
          <legend className="mb-2 block text-sm font-medium text-slate-700">Matériel</legend>
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {vocab.materiel.map((entry) => (
              <CheckboxField
                key={entry.key}
                label={entry.label}
                checked={equipment.includes(entry.key as Materiel)}
                onChange={() => toggleEquipment(entry.key as Materiel)}
              />
            ))}
          </div>
          <p className="mt-1.5 text-xs text-slate-500">
            « Aucun » exclut les autres choix. Une salle publique présélectionne les machines.
          </p>
        </fieldset>

        <ChipGroup legend="Focus" multiple hint="Plusieurs choix possibles.">
          {vocab.focus.map((entry) => (
            <Chip
              key={entry.key}
              selected={focus.includes(entry.key as SportFocus)}
              onClick={() => toggleIn(focus, setFocus, entry.key as SportFocus)}
            >
              {entry.label}
            </Chip>
          ))}
        </ChipGroup>

        <ChipGroup legend="Zones à éviter" multiple hint="Le coach évitera les exercices qui les sollicitent.">
          {vocab.zones.map((entry) => (
            <Chip
              key={entry.key}
              selected={zones.includes(entry.key as ZoneAEviter)}
              onClick={() => toggleIn(zones, setZones, entry.key as ZoneAEviter)}
            >
              {entry.label}
            </Chip>
          ))}
        </ChipGroup>

        <TextareaField
          label="Notes pour le coach"
          rows={3}
          maxLength={500}
          placeholder="Ex. : j’ai mal au genou droit, je veux travailler les fessiers"
          error={errors.notes?.message}
          {...register("notes")}
        />

        <div className="flex flex-wrap items-center gap-2">
          <Button type="submit" size="lg" loading={pending}>
            Proposer une séance
          </Button>
          {onClose ? (
            <Button variant="secondary" size="lg" onClick={onClose} disabled={pending}>
              Annuler
            </Button>
          ) : null}
        </div>

        <p className="text-xs leading-5 text-slate-500">{messages.sportDisclaimer}</p>
      </form>
    </Card>
  );
}

export default GenerateForm;
