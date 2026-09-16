"use client";

import Link from "next/link";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { api, getApiErrorMessage, setAuthToken } from "@/lib/api";

export default function RegisterPage() {
  const router = useRouter();

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState("");

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setLoading(true);
    setErrorMessage("");

    try {
      const response = await api.post("/register", {
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });

      const token = response.data.token;
      const user = response.data.user;

      localStorage.setItem("token", token);
      localStorage.setItem("user", JSON.stringify(user));
      setAuthToken(token);

      router.push("/dashboard");
    } catch (error: unknown) {
      setErrorMessage(getApiErrorMessage(error, "Inscription impossible."));
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(183,228,74,0.24),_transparent_36%),linear-gradient(180deg,#f7fcef_0%,#eef9e3_100%)] px-4 py-8 text-[#0b3f2f]">
      <div className="mx-auto grid min-h-[calc(100vh-4rem)] max-w-6xl items-center gap-8 lg:grid-cols-[0.95fr_1.05fr]">
        <section className="order-2 mx-auto w-full max-w-md rounded-[2rem] border border-emerald-100 bg-white/92 p-8 shadow-[0_30px_80px_rgba(15,23,42,0.12)] backdrop-blur lg:order-1">
          <div className="mb-8 text-center">
            <p className="mb-3 inline-flex rounded-full bg-lime-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-lime-700">
              Rejoins suit mavioh
            </p>
            <h2 className="text-3xl font-semibold tracking-tight text-slate-900">
              Créer un compte
            </h2>
            <p className="mt-3 text-sm leading-6 text-slate-500">
              Crée ton espace nutrition et synchronise tes données.
            </p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label htmlFor="name" className="mb-2 block text-sm font-medium text-slate-700">
                Nom
              </label>
              <input
                id="name"
                type="text"
                autoComplete="name"
                placeholder="Ton nom"
                value={name}
                onChange={(e) => setName(e.target.value)}
                className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
                required
              />
            </div>

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
                autoComplete="new-password"
                placeholder="Minimum 8 caractères"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
                required
              />
            </div>

            <div>
              <label
                htmlFor="password_confirmation"
                className="mb-2 block text-sm font-medium text-slate-700"
              >
                Confirmation du mot de passe
              </label>
              <input
                id="password_confirmation"
                type="password"
                autoComplete="new-password"
                placeholder="Confirme ton mot de passe"
                value={passwordConfirmation}
                onChange={(e) => setPasswordConfirmation(e.target.value)}
                className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-lime-600 focus:ring-4 focus:ring-lime-600/10"
                required
              />
            </div>

            {errorMessage ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm leading-6 text-rose-700">
                {errorMessage}
              </div>
            ) : null}

            <button
              type="submit"
              disabled={loading}
              className="inline-flex w-full items-center justify-center rounded-2xl bg-emerald-700 px-4 py-3.5 font-medium text-white transition hover:-translate-y-0.5 hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {loading ? "Création..." : "Créer mon compte"}
            </button>
          </form>

          <p className="mt-6 text-center text-sm text-slate-600">
            Déjà un compte ?{" "}
            <Link href="/login" className="font-semibold text-lime-700 underline-offset-4 hover:underline">
              Se connecter
            </Link>
          </p>
        </section>

        <section className="order-1 hidden overflow-hidden rounded-[2rem] border border-emerald-200/60 bg-[#005c3f] p-10 text-white shadow-2xl lg:order-2 lg:block">
          <div className="flex h-full flex-col justify-between gap-10">
            <div>
              <p className="mb-4 inline-flex rounded-full border border-lime-200/30 bg-lime-200/10 px-4 py-1 text-sm font-medium text-lime-100">
                Ton profil nutritionnel
              </p>
              <h1 className="max-w-xl text-4xl font-semibold leading-tight tracking-tight text-white xl:text-5xl">
                Inscris-toi pour gérer tes repas, tes stocks et tes objectifs.
              </h1>
              <p className="mt-6 max-w-lg text-base leading-7 text-slate-300">
                La création de compte envoie directement un token Sanctum pour connecter les apps Flutter et Next.
              </p>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p className="text-2xl font-semibold text-lime-300">1</p>
                <p className="mt-1 text-sm text-slate-300">Créer le compte</p>
              </div>
              <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p className="text-2xl font-semibold text-lime-200">2</p>
                <p className="mt-1 text-sm text-slate-300">Se connecter</p>
              </div>
              <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                <p className="text-2xl font-semibold text-amber-300">3</p>
                <p className="mt-1 text-sm text-slate-300">Accéder au dashboard</p>
              </div>
            </div>
          </div>
        </section>
      </div>
    </main>
  );
}
