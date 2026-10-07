"use client";

import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { Chip } from "@/components/sport/chips";
import { ExerciseFigure } from "@/components/sport/exercise-figure";
import { beep, keepScreenAwake, unlockAudio, vibrate } from "@/components/sport/guided-audio";
import { exerciseEtapes, formatDuree, formatMinSec } from "@/components/sport/prescription";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Field } from "@/components/ui/field";
import { getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { parseDecimal } from "@/lib/format";
import { BLOCK_LABELS } from "@/lib/vocab";
import type { ExerciseMovement, SeanceEtape, WorkoutBlockKey, WorkoutExercise } from "@/lib/types/api";

/**
 * Séance guidée (docs/SPORT_V2.md §6) : plein écran, une étape à la fois, compte à rebours,
 * cible en cours, étape suivante, pause / précédent / passer, bip + vibration aux changements et
 * 3-2-1 avant la fin d’une étape minutée.
 *
 * Le temps se calcule TOUJOURS depuis des horodatages (pas de compteur qui dérive) : un onglet
 * mis en arrière-plan, un téléphone en veille ou un rechargement retrouvent la bonne étape. La
 * progression est gardée sur l’appareil (localStorage) pour reprendre plus tard.
 */

const BLOCK_ORDER: WorkoutBlockKey[] = ["echauffement", "principal", "retour_au_calme"];

export type GuidedStep = SeanceEtape & {
  exerciseId: number;
  exerciseName: string;
  block: WorkoutBlockKey;
  movement: ExerciseMovement | null;
  consigne: string | null;
  exerciseIndex: number;
  exerciseTotal: number;
};

/** Toutes les étapes de la séance, bloc par bloc, exercice par exercice. */
export function buildGuidedSteps(exercises: WorkoutExercise[]): GuidedStep[] {
  const ordered = [...exercises].sort(
    (a, b) =>
      BLOCK_ORDER.indexOf(a.block) - BLOCK_ORDER.indexOf(b.block) || a.position - b.position,
  );
  return ordered.flatMap((exercise, exerciseIndex) =>
    exerciseEtapes(exercise).map((etape) => ({
      ...etape,
      exerciseId: exercise.id,
      exerciseName: exercise.name,
      block: exercise.block,
      movement: exercise.movement ?? null,
      consigne: exercise.prescription?.consigne?.trim() || null,
      exerciseIndex,
      exerciseTotal: ordered.length,
    })),
  );
}

/* ------------------------------------------------------------------ */
/* État d’avancement (horodatages)                                      */
/* ------------------------------------------------------------------ */

type Run = {
  index: number;
  /** Temps déjà passé sur l’étape avant la dernière reprise (ms). */
  accMs: number;
  /** Début de la portion en cours, null en pause. */
  since: number | null;
  startedAt: number;
  endedAt: number | null;
};

const cle = (sessionId: number) => `mavioh.seance-guidee.${sessionId}`;
const CLE_SON = "mavioh.seance-guidee.son";

function lireRun(sessionId: number, total: number): Run | null {
  try {
    const brut = window.localStorage.getItem(cle(sessionId));
    if (!brut) return null;
    const data = JSON.parse(brut) as Run & { savedAt?: number };
    if (typeof data.index !== "number" || data.index < 0 || data.index >= total || data.endedAt) return null;
    // Reprise en pause, au point exact où l’on était au moment de la sauvegarde.
    const acc = data.accMs + (data.since !== null && data.savedAt ? Math.max(0, data.savedAt - data.since) : 0);
    return { index: data.index, accMs: acc, since: null, startedAt: data.startedAt, endedAt: null };
  } catch {
    return null;
  }
}

function ecrireRun(sessionId: number, run: Run): void {
  try {
    window.localStorage.setItem(cle(sessionId), JSON.stringify({ ...run, savedAt: Date.now() }));
  } catch {
    /* stockage indisponible (navigation privée) : on continue sans */
  }
}

export function clearGuidedRun(sessionId: number): void {
  try {
    window.localStorage.removeItem(cle(sessionId));
  } catch {
    /* ignoré */
  }
}

function lireSon(): boolean {
  try {
    return window.localStorage.getItem(CLE_SON) !== "off";
  } catch {
    return true;
  }
}

function elapsedMs(run: Run, now: number): number {
  return run.accMs + (run.since !== null ? Math.max(0, now - run.since) : 0);
}

/** Fait avancer les étapes minutées écoulées (plusieurs d’un coup après une mise en veille). */
function settle(run: Run, now: number, steps: GuidedStep[]): Run {
  let r = run;
  while (r.endedAt === null && r.since !== null) {
    const duree = steps[r.index]?.duree_sec;
    if (!duree) break;
    const ecoule = elapsedMs(r, now);
    if (ecoule < duree * 1000) break;
    const surplus = ecoule - duree * 1000;
    if (r.index >= steps.length - 1) {
      r = { ...r, accMs: duree * 1000, since: null, endedAt: now - surplus };
      break;
    }
    r = { ...r, index: r.index + 1, accMs: 0, since: now - surplus };
  }
  return r;
}

/* ------------------------------------------------------------------ */

export type GuidedSessionProps = {
  sessionId: number;
  title: string;
  steps: GuidedStep[];
  onClose: () => void;
  /** Enregistre la séance comme réalisée (endpoint `complete`). */
  onFinish: (values: { duration_min: number; rpe: number }) => Promise<void>;
};

export function GuidedSession(props: GuidedSessionProps) {
  if (typeof document === "undefined") return null;
  return createPortal(<GuidedSessionScreen {...props} />, document.body);
}

const RPE_HINTS: Record<number, string> = {
  1: "Très facile",
  2: "Facile",
  3: "Facile",
  4: "Modéré",
  5: "Modéré",
  6: "Soutenu",
  7: "Difficile",
  8: "Très difficile",
  9: "Très difficile",
  10: "Maximal",
};

function GuidedSessionScreen({ sessionId, title, steps, onClose, onFinish }: GuidedSessionProps) {
  const [run, setRun] = useState<Run>(() => {
    const now = Date.now();
    return lireRun(sessionId, steps.length) ?? { index: 0, accMs: 0, since: now, startedAt: now, endedAt: null };
  });
  const [now, setNow] = useState(() => Date.now());
  const [son, setSon] = useState(lireSon);
  const [quitter, setQuitter] = useState(false);
  const [rpe, setRpe] = useState<number | null>(null);
  const [duree, setDuree] = useState<string>("");
  const [enregistrement, setEnregistrement] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);

  const runRef = useRef(run);
  const sonRef = useRef(son);
  const dernierDecompte = useRef<string>("");
  const rootRef = useRef<HTMLDivElement>(null);

  function signal(kind: "etape" | "fin") {
    if (sonRef.current) beep(kind);
    vibrate(kind === "fin" ? [200, 100, 200, 100, 300] : [180]);
  }

  function commit(next: Run) {
    runRef.current = next;
    setRun(next);
    ecrireRun(sessionId, next);
  }

  /* Horloge : relit l’heure, avance les étapes écoulées, décompte 3-2-1. */
  useEffect(() => {
    function tick() {
      const t = Date.now();
      const avant = runRef.current;
      const apres = settle(avant, t, steps);
      if (apres !== avant) {
        commit(apres);
        signal(apres.endedAt !== null ? "fin" : "etape");
      }
      const etape = steps[apres.index];
      if (apres.endedAt === null && apres.since !== null && etape?.duree_sec) {
        const reste = Math.ceil((etape.duree_sec * 1000 - elapsedMs(apres, t)) / 1000);
        const marque = `${apres.index}:${reste}`;
        if (reste >= 1 && reste <= 3 && etape.duree_sec > 5 && dernierDecompte.current !== marque) {
          dernierDecompte.current = marque;
          if (sonRef.current) beep("decompte");
          vibrate(60);
        }
      }
      setNow(t);
    }
    const timer = window.setInterval(tick, 200);
    document.addEventListener("visibilitychange", tick);
    return () => {
      window.clearInterval(timer);
      document.removeEventListener("visibilitychange", tick);
    };
    // commit/signal ne lisent que des refs et des setters stables.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [steps, sessionId]);

  /* Écran allumé, défilement de la page bloqué, focus sur l’écran (raccourcis clavier). */
  useEffect(() => {
    const relacher = keepScreenAwake();
    const overflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    rootRef.current?.focus();
    return () => {
      relacher();
      document.body.style.overflow = overflow;
    };
  }, []);

  const total = steps.length;
  const fini = run.endedAt !== null;
  const etape = steps[Math.min(run.index, total - 1)];
  const suivante = steps[run.index + 1] ?? null;
  const enPause = run.since === null && !fini;
  const ecoule = elapsedMs(run, now);
  const dureeEtape = etape?.duree_sec ?? null;
  const resteSec = dureeEtape ? Math.max(0, Math.ceil((dureeEtape * 1000 - ecoule) / 1000)) : null;
  const ratio = dureeEtape ? Math.min(1, ecoule / (dureeEtape * 1000)) : 0;
  const repos = etape?.type === "repos" || etape?.type === "recup";
  const dureeParDefaut = Math.max(1, Math.round(((run.endedAt ?? now) - run.startedAt) / 60000));

  /* ---------------- actions ---------------- */

  function aller(index: number, relancer = false) {
    const r = runRef.current;
    const t = Date.now();
    dernierDecompte.current = "";
    commit({ ...r, index, accMs: 0, since: r.since !== null || relancer ? t : null, endedAt: null });
    setNow(t);
  }

  function terminer() {
    const r = runRef.current;
    const t = Date.now();
    commit({ ...r, since: null, endedAt: t });
    setNow(t);
    signal("fin");
  }

  function suivant(relancer = false) {
    unlockAudio();
    const r = runRef.current;
    if (r.index >= total - 1) {
      terminer();
      return;
    }
    aller(r.index + 1, relancer);
    signal("etape");
  }

  function precedent() {
    unlockAudio();
    const r = runRef.current;
    // Comme un lecteur audio : un premier appui revient au début de l’étape.
    if (elapsedMs(r, Date.now()) > 3000 || r.index === 0) aller(r.index);
    else aller(r.index - 1);
  }

  function basculerPause() {
    unlockAudio();
    const r = runRef.current;
    if (r.endedAt !== null) return;
    const t = Date.now();
    commit(r.since === null ? { ...r, since: t } : { ...r, accMs: elapsedMs(r, t), since: null });
    setNow(t);
  }

  function basculerSon() {
    unlockAudio();
    const next = !sonRef.current;
    sonRef.current = next;
    setSon(next);
    try {
      window.localStorage.setItem(CLE_SON, next ? "on" : "off");
    } catch {
      /* ignoré */
    }
    if (next) beep("decompte");
  }

  function demanderQuitter() {
    const r = runRef.current;
    // Pause pendant la question : le minuteur ne file pas pendant qu’on hésite.
    if (r.since !== null && r.endedAt === null) {
      const t = Date.now();
      commit({ ...r, accMs: elapsedMs(r, t), since: null });
      setNow(t);
    }
    setQuitter(true);
  }

  function reprendreApresFin() {
    // « Revenir » depuis l’écran de fin : dernière étape, en pause.
    commit({ ...runRef.current, endedAt: null, since: null });
  }

  async function enregistrer() {
    if (rpe === null) return;
    const minutes = parseDecimal(duree) ?? dureeParDefaut;
    setEnregistrement(true);
    setErreur(null);
    try {
      await onFinish({ duration_min: Math.max(1, Math.min(600, Math.round(minutes))), rpe });
      clearGuidedRun(sessionId);
    } catch (error) {
      setErreur(getErrorMessage(error));
    } finally {
      setEnregistrement(false);
    }
  }

  function clavier(event: KeyboardEvent<HTMLDivElement>) {
    if (fini || quitter) {
      if (event.key === "Escape" && quitter) setQuitter(false);
      return;
    }
    const cible = event.target as HTMLElement;
    if (cible.tagName === "INPUT" || cible.tagName === "TEXTAREA") return;
    if (event.key === " ") {
      event.preventDefault();
      basculerPause();
    } else if (event.key === "ArrowRight") {
      suivant();
    } else if (event.key === "ArrowLeft") {
      precedent();
    } else if (event.key === "Escape") {
      demanderQuitter();
    }
  }

  /* ---------------- rendu ---------------- */

  return (
    <div
      ref={rootRef}
      tabIndex={-1}
      role="dialog"
      aria-modal="true"
      aria-label={`Séance guidée : ${title}`}
      onKeyDown={clavier}
      className={cn(
        "fixed inset-0 z-[55] flex flex-col overflow-y-auto text-white outline-none transition-colors duration-500",
        fini ? "bg-emerald-900" : repos ? "bg-sky-900" : "bg-emerald-900",
      )}
      style={{
        paddingTop: "max(env(safe-area-inset-top), 0.75rem)",
        paddingBottom: "max(env(safe-area-inset-bottom), 0.75rem)",
      }}
    >
      {/* Barre du haut */}
      <div className="mx-auto flex w-full max-w-xl items-center justify-between gap-2 px-4">
        <button
          type="button"
          onClick={fini ? onClose : demanderQuitter}
          className="inline-flex min-h-10 items-center rounded-xl px-3 text-sm font-semibold text-white/90 hover:bg-white/10 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-white/30"
        >
          {fini ? "Fermer" : "Quitter"}
        </button>
        <p className="min-w-0 truncate text-center text-sm font-medium text-white/80">{title}</p>
        <button
          type="button"
          onClick={basculerSon}
          aria-pressed={son}
          className="inline-flex min-h-10 items-center rounded-xl px-3 text-sm font-semibold text-white/90 hover:bg-white/10 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-white/30"
        >
          {son ? "Son activé" : "Son coupé"}
        </button>
      </div>

      {/* Progression générale */}
      <div className="mx-auto mt-2 w-full max-w-xl px-4">
        <div
          className="h-1.5 overflow-hidden rounded-full bg-white/15"
          role="progressbar"
          aria-label="Progression de la séance"
          aria-valuemin={0}
          aria-valuemax={total}
          aria-valuenow={fini ? total : run.index}
        >
          <div
            className="h-full rounded-full bg-white transition-[width] duration-300"
            style={{ width: `${((fini ? total : run.index + ratio) / Math.max(1, total)) * 100}%` }}
          />
        </div>
        {!fini && etape ? (
          <p className="mt-1.5 text-xs text-white/70">
            {BLOCK_LABELS[etape.block]} · exercice {etape.exerciseIndex + 1}/{etape.exerciseTotal} · étape{" "}
            {run.index + 1}/{total}
          </p>
        ) : null}
      </div>

      {fini ? (
        <div className="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center px-4 py-6">
          <p className="text-center text-3xl font-bold">Bravo, séance terminée !</p>
          <p className="mt-2 text-center text-sm text-emerald-100">
            Dis-nous comment tu l’as vécue : les calories se calculent avec ta durée réelle et ton ressenti.
          </p>
          <Card className="mt-6 text-slate-900">
            {erreur ? (
              <Banner tone="error" className="mb-4" onClose={() => setErreur(null)}>
                {erreur}
              </Banner>
            ) : null}
            <fieldset>
              <legend className="mb-2 text-sm font-medium text-slate-700">
                Effort ressenti
                {rpe !== null ? (
                  <span className="font-semibold text-slate-900">{` : ${rpe}/10 — ${RPE_HINTS[rpe]}`}</span>
                ) : null}
              </legend>
              <div className="grid grid-cols-5 gap-2" role="radiogroup" aria-label="Effort ressenti de 1 à 10">
                {Array.from({ length: 10 }, (_, i) => i + 1).map((value) => (
                  <Chip
                    key={value}
                    role="radio"
                    selected={rpe === value}
                    onClick={() => setRpe(value)}
                    title={RPE_HINTS[value]}
                    className="px-0"
                  >
                    {value}
                  </Chip>
                ))}
              </div>
              <div className="mt-1.5 flex justify-between text-xs text-slate-500">
                <span>très facile</span>
                <span>maximal</span>
              </div>
            </fieldset>
            <Field
              label="Durée réelle (minutes)"
              inputMode="numeric"
              wrapperClassName="mt-4"
              value={duree}
              placeholder={String(dureeParDefaut)}
              onChange={(event) => setDuree(event.target.value)}
            />
            <div className="mt-5 flex flex-wrap gap-2">
              <Button size="lg" onClick={enregistrer} loading={enregistrement} disabled={rpe === null} block>
                {rpe === null ? "Choisis ton ressenti" : "Terminer la séance"}
              </Button>
              <Button variant="ghost" onClick={reprendreApresFin} disabled={enregistrement}>
                Revenir à la dernière étape
              </Button>
            </div>
          </Card>
        </div>
      ) : etape ? (
        <>
          <div className="mx-auto flex w-full max-w-xl flex-1 flex-col items-center justify-center px-4 py-4 text-center">
            <div className="flex items-center gap-3">
              <span className="grid place-items-center rounded-2xl bg-white p-1.5 text-slate-900">
                <ExerciseFigure movement={etape.movement} name={etape.exerciseName} size={48} still={enPause} />
              </span>
              <p className="max-w-[16rem] text-left text-lg font-semibold leading-6 text-white/95">
                {etape.exerciseName}
              </p>
            </div>

            <p className="mt-4 text-3xl font-bold sm:text-4xl" aria-live="polite">
              {etape.label}
            </p>

            {dureeEtape ? (
              <Anneau ratio={ratio} repos={repos}>
                <span className="text-6xl font-bold tabular-nums sm:text-7xl">{formatMinSec(resteSec ?? 0)}</span>
                {enPause ? <span className="mt-1 text-sm font-semibold uppercase tracking-wide">En pause</span> : null}
              </Anneau>
            ) : (
              <div className="mt-6 flex w-full max-w-sm flex-col items-center gap-3">
                <p className="text-sm text-white/70">
                  {enPause ? "En pause · " : ""}
                  {formatMinSec(Math.floor(ecoule / 1000))} sur cette série
                </p>
                <Button size="lg" variant="secondary" block onClick={() => suivant(true)} className="h-16 text-lg">
                  Série terminée
                </Button>
              </div>
            )}

            {etape.cible ? (
              <p className="mt-5 rounded-2xl bg-white/10 px-4 py-3 text-lg font-semibold leading-7 sm:text-xl">
                {etape.cible}
              </p>
            ) : null}
            {etape.consigne && !repos ? (
              <p className="mt-3 max-w-md text-sm leading-6 text-white/80">{etape.consigne}</p>
            ) : null}
          </div>

          <div className="mx-auto w-full max-w-xl px-4">
            <div className="rounded-2xl bg-black/15 px-4 py-3">
              <p className="text-xs font-semibold uppercase tracking-wide text-white/60">Ensuite</p>
              {suivante ? (
                <p className="mt-0.5 text-sm text-white/90">
                  <span className="font-semibold">{suivante.label}</span>
                  {suivante.exerciseId !== etape.exerciseId ? ` · ${suivante.exerciseName}` : ""}
                  {suivante.duree_sec ? ` · ${formatDuree(suivante.duree_sec)}` : ""}
                  {suivante.cible ? <span className="block text-white/70">{suivante.cible}</span> : null}
                </p>
              ) : (
                <p className="mt-0.5 text-sm text-white/90">Fin de la séance</p>
              )}
            </div>

            <div className="mt-3 grid grid-cols-3 gap-2">
              <Button variant="ghost" size="lg" onClick={precedent} className="text-white hover:bg-white/10 hover:text-white">
                ‹ Précédent
              </Button>
              <Button size="lg" variant="secondary" onClick={basculerPause}>
                {enPause ? "Reprendre" : "Pause"}
              </Button>
              <Button variant="ghost" size="lg" onClick={() => suivant()} className="text-white hover:bg-white/10 hover:text-white">
                Passer ›
              </Button>
            </div>
          </div>
        </>
      ) : null}

      {quitter ? (
        <div className="fixed inset-0 z-10 flex items-end justify-center bg-slate-950/60 p-4 sm:items-center">
          <Card className="w-full max-w-md text-slate-900" role="alertdialog" aria-label="Quitter la séance guidée ?">
            <p className="text-base font-semibold">Quitter la séance guidée ?</p>
            <p className="mt-1 text-sm text-slate-600">
              Ta progression reste sur cet appareil : tu pourras reprendre là où tu t’es arrêté·e.
            </p>
            <div className="mt-4 flex flex-wrap gap-2">
              <Button
                onClick={() => {
                  setQuitter(false);
                  rootRef.current?.focus();
                }}
              >
                Continuer la séance
              </Button>
              <Button variant="secondary" onClick={onClose}>
                Quitter
              </Button>
              <Button variant="ghost" onClick={terminer}>
                J’ai fini, enregistrer
              </Button>
            </div>
          </Card>
        </div>
      ) : null}
    </div>
  );
}

/** Anneau de progression autour du compte à rebours. */
function Anneau({ ratio, repos, children }: { ratio: number; repos: boolean; children: ReactNode }) {
  const r = 54;
  const circonference = 2 * Math.PI * r;
  return (
    <div className="relative mt-5 grid size-60 place-items-center sm:size-72">
      <svg viewBox="0 0 120 120" className="absolute inset-0 size-full -rotate-90" aria-hidden="true">
        <circle cx="60" cy="60" r={r} fill="none" stroke="currentColor" strokeOpacity={0.15} strokeWidth={6} />
        <circle
          cx="60"
          cy="60"
          r={r}
          fill="none"
          stroke={repos ? "#7dd3fc" : "#6ee7b7"}
          strokeWidth={6}
          strokeLinecap="round"
          strokeDasharray={circonference}
          strokeDashoffset={circonference * (1 - ratio)}
          style={{ transition: "stroke-dashoffset 0.2s linear" }}
        />
      </svg>
      <div className="relative flex flex-col items-center">{children}</div>
    </div>
  );
}

export default GuidedSession;
