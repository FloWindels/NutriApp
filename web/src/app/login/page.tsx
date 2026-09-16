"use client";

import Link from "next/link";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { getApiErrorMessage, setAuthToken } from "@/lib/api";

export default function LoginPage() {
  const router = useRouter();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState("");
  const [successMessage, setSuccessMessage] = useState("");

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setLoading(true);
    setErrorMessage("");
    setSuccessMessage("");

    try {
      const response = await fetch("/api/auth/login", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({ email, password }),
      });

      const payload = await response.json();

      if (!response.ok) {
        throw {
          response: {
            data: payload,
          },
        };
      }

      const token = payload.token;
      const user = payload.user;

      localStorage.setItem("token", token);
      localStorage.setItem("user", JSON.stringify(user));
      setAuthToken(token);

      setSuccessMessage("Connexion réussie. Redirection...");
      router.replace("/dashboard");
    } catch (error: unknown) {
      setErrorMessage(
        getApiErrorMessage(error, "Connexion impossible. Vérifie tes identifiants."),
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(183,228,74,0.28),_transparent_36%),linear-gradient(180deg,#f7fcef_0%,#ecf9df_100%)] px-4 py-8 text-[#0b3f2f]">
      <div className="mx-auto grid min-h-[calc(100vh-4rem)] max-w-6xl items-center gap-8 lg:grid-cols-[1.1fr_0.9fr]">
        <section className="hidden overflow-hidden rounded-[2rem] border border-emerald-200/60 bg-[#005c3f] p-10 text-white shadow-2xl lg:block">
          <div className="flex h-full flex-col justify-between gap-10">
            <div>
              <p className="mb-4 inline-flex rounded-full border border-lime-200/30 bg-lime-200/10 px-4 py-1 text-sm font-medium text-lime-100">
                suit mavioh · Solution nutrition intelligente
              </p>
              <h1 className="max-w-xl text-4xl font-semibold leading-tight tracking-tight text-white xl:text-5xl">
                Une application conçue pour simplifier le suivi nutritionnel au quotidien.
              </h1>
              <p className="mt-6 max-w-lg text-base leading-7 text-slate-300">
                Suivi des objectifs, calcul des besoins, organisation des repas et lecture claire des
                indicateurs clés dans une interface pensée pour aller droit à l’essentiel.
              </p>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p className="text-2xl font-semibold text-emerald-300">Suivi</p>
                <p className="mt-1 text-sm text-slate-300">Poids, objectifs et progression</p>
              </div>
              <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p className="text-2xl font-semibold text-sky-300">Calcul</p>
                <p className="mt-1 text-sm text-slate-300">Repères clairs et automatiques</p>
              </div>
              <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p className="text-2xl font-semibold text-amber-300">Guidé</p>
                <p className="mt-1 text-sm text-slate-300">Expérience simple et rassurante</p>
              </div>
            </div>
          </div>
        </section>

        <section className="mx-auto w-full max-w-md rounded-[2rem] border border-emerald-100 bg-white/92 p-8 shadow-[0_30px_80px_rgba(15,23,42,0.12)] backdrop-blur">
          <div className="mb-8 text-center">
            <p className="mb-3 inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">
              Bienvenue
            </p>
            <h2 className="text-3xl font-semibold tracking-tight text-slate-900">
              Connexion
            </h2>
            <p className="mt-3 text-sm leading-6 text-slate-500">
              Accède à ton espace nutrition et retrouve tous tes outils de suivi en un seul endroit.
            </p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label htmlFor="email" className="mb-2 block text-sm font-medium text-slate-700">
                Email
              </label>
              <input
                id="email"
                type="email"
                autoComplete="email"
                placeholder="ton@email.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
                required
              />
            </div>

            <div>
              <label htmlFor="password" className="mb-2 block text-sm font-medium text-slate-700">
                Mot de passe
              </label>
              <input
                id="password"
                type="password"
                autoComplete="current-password"
                placeholder="********"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
                required
              />
            </div>

            {errorMessage ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-6 text-rose-700">
                {errorMessage}
              </div>
            ) : null}

            {successMessage ? (
              <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-700">
                {successMessage}
              </div>
            ) : null}

            <button
              type="submit"
              disabled={loading}
              className="inline-flex w-full items-center justify-center rounded-2xl bg-emerald-700 px-4 py-3.5 font-medium text-white transition hover:-translate-y-0.5 hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {loading ? "Connexion..." : "Se connecter"}
            </button>
          </form>

          <p className="mt-6 text-center text-sm text-slate-600">
            Pas encore de compte ?{" "}
            <Link href="/register" className="font-semibold text-lime-700 underline-offset-4 hover:underline">
              Créer un compte
            </Link>
          </p>
        </section>
      </div>
    </main>
  );
}
