import type { ReactNode } from "react";
import { cn } from "@/lib/cn";
import { BRAND, TAGLINE } from "@/lib/messages";

export type AuthShellProps = {
  /** Small uppercase label above the card title. */
  eyebrow: string;
  title: string;
  subtitle?: ReactNode;
  children: ReactNode;
  /** Link line under the form (« Pas encore de compte ? … »). */
  footer?: ReactNode;
  /** Hero on the left (desktop only). */
  heroTitle?: string;
  heroText?: string;
  /** Puts the form on the left (register page). */
  reverse?: boolean;
};

const heroStats = [
  { value: "Suivi", tone: "text-emerald-300", text: "Poids, objectifs et progression" },
  { value: "Coach", tone: "text-sky-300", text: "Recommandations du jour, expliquées" },
  { value: "Sport", tone: "text-amber-300", text: "Séances et calories réintégrées" },
];

/** Two-column auth layout shared by login, register and password pages. */
export function AuthShell({
  eyebrow,
  title,
  subtitle,
  children,
  footer,
  heroTitle = "Une application conçue pour simplifier ton suivi nutrition et sport au quotidien.",
  heroText = "Objectifs calculés à partir de ton profil, repas en deux clics, stock et courses partagés avec ton foyer, séances proposées par ton coach.",
  reverse = false,
}: AuthShellProps) {
  return (
    <main className="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(183,228,74,0.28),_transparent_36%),linear-gradient(180deg,#f7fcef_0%,#ecf9df_100%)] px-4 py-8 text-[#0b3f2f]">
      <div
        className={cn(
          "mx-auto grid min-h-[calc(100vh-4rem)] max-w-6xl items-center gap-8",
          reverse ? "lg:grid-cols-[0.95fr_1.05fr]" : "lg:grid-cols-[1.1fr_0.9fr]",
        )}
      >
        <section
          aria-label={`Présentation de ${BRAND}`}
          className={cn(
            "hidden overflow-hidden rounded-[2rem] border border-emerald-200/60 bg-[#005c3f] p-10 text-white shadow-2xl lg:block",
            reverse && "lg:order-2",
          )}
        >
          <div className="flex h-full flex-col justify-between gap-10">
            <div>
              <p className="mb-4 inline-flex rounded-full border border-lime-200/30 bg-lime-200/10 px-4 py-1 text-sm font-medium text-lime-100">
                {BRAND} · {TAGLINE}
              </p>
              <h1 className="max-w-xl text-4xl font-semibold leading-tight tracking-tight text-white xl:text-5xl">
                {heroTitle}
              </h1>
              <p className="mt-6 max-w-lg text-base leading-7 text-slate-300">{heroText}</p>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              {heroStats.map((stat) => (
                <div key={stat.value} className="rounded-2xl border border-white/10 bg-white/5 p-4">
                  <p className={cn("text-2xl font-semibold", stat.tone)}>{stat.value}</p>
                  <p className="mt-1 text-sm text-slate-300">{stat.text}</p>
                </div>
              ))}
            </div>
          </div>
        </section>

        <section
          className={cn(
            "mx-auto w-full max-w-md rounded-[2rem] border border-emerald-100 bg-white/92 p-8 shadow-[0_30px_80px_rgba(15,23,42,0.12)] backdrop-blur",
            reverse && "lg:order-1",
          )}
        >
          <div className="mb-8 text-center">
            <p className="mb-3 inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">
              {eyebrow}
            </p>
            <h2 className="text-3xl font-semibold tracking-tight text-slate-900">{title}</h2>
            {subtitle ? <p className="mt-3 text-sm leading-6 text-slate-500">{subtitle}</p> : null}
          </div>

          {children}

          {footer ? <p className="mt-6 text-center text-sm text-slate-600">{footer}</p> : null}
        </section>
      </div>
    </main>
  );
}

export default AuthShell;
