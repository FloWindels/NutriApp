"use client";

import Image from "next/image";
import Link from "next/link";
import { useState } from "react";

const recipeSlides = [
  {
    id: "recipe-1",
    title: "Bowl saumon avocat",
    description:
      "Riche en protéines et en bons lipides, idéal pour un repas complet après une matinée active.",
    image: "/recipes/recipe-1.jpg",
    kcal: "620 kcal",
  },
  {
    id: "recipe-2",
    title: "Salade méditerranéenne",
    description:
      "Assiette légère et équilibrée avec legumes croquants, source de fibres et glucides modérées.",
    image: "/recipes/recipe-2.jpg",
    kcal: "540 kcal",
  },
];

export default function DashboardPage() {
  const [recipeIndex, setRecipeIndex] = useState(0);
  const activeRecipe = recipeSlides[recipeIndex];

  function showPreviousRecipe() {
    setRecipeIndex((prev) => (prev === 0 ? recipeSlides.length - 1 : prev - 1));
  }

  function showNextRecipe() {
    setRecipeIndex((prev) => (prev === recipeSlides.length - 1 ? 0 : prev + 1));
  }

  return (
    <>
      <div className="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
        <section className="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-[0_14px_40px_rgba(15,23,42,0.06)] sm:p-6">
          <div className="flex items-center justify-between">
            <p className="text-sm font-semibold text-slate-900">Vue nutrition</p>
            <span className="rounded-full border border-lime-200 bg-lime-50 px-3 py-1 text-xs font-medium text-lime-700">
              Aujourd’hui
            </span>
          </div>

          <div className="mt-5 grid gap-4 sm:grid-cols-3">
            <article className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <p className="text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Protéines</p>
              <p className="mt-2 text-2xl font-semibold text-slate-900">34%</p>
              <div className="mt-3 h-2 rounded-full bg-slate-200">
                <div className="h-2 w-[34%] rounded-full bg-emerald-500" />
              </div>
            </article>

            <article className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <p className="text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Glucides</p>
              <p className="mt-2 text-2xl font-semibold text-slate-900">41%</p>
              <div className="mt-3 h-2 rounded-full bg-slate-200">
                <div className="h-2 w-[41%] rounded-full bg-lime-500" />
              </div>
            </article>

            <article className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <p className="text-xs font-medium uppercase tracking-[0.14em] text-slate-500">Lipides</p>
              <p className="mt-2 text-2xl font-semibold text-slate-900">25%</p>
              <div className="mt-3 h-2 rounded-full bg-slate-200">
                <div className="h-2 w-[25%] rounded-full bg-amber-500" />
              </div>
            </article>
          </div>

          <div className="mt-5 grid gap-4 sm:grid-cols-2">
            <article className="rounded-2xl border border-slate-200 bg-white p-5">
              <p className="text-sm font-medium text-slate-500">Hydratation</p>
              <p className="mt-2 text-3xl font-semibold text-slate-950">1.7L</p>
              <div className="mt-4 h-2.5 rounded-full bg-slate-200">
                <div className="h-2.5 w-[68%] rounded-full bg-emerald-500" />
              </div>
              <p className="mt-2 text-xs text-slate-500">Objectif 2.5L</p>
            </article>

            <article className="rounded-2xl border border-slate-200 bg-white p-5">
              <p className="text-sm font-medium text-slate-500">Calories</p>
              <p className="mt-2 text-3xl font-semibold text-slate-950">1860</p>
              <div className="mt-4 h-2.5 rounded-full bg-slate-200">
                <div className="h-2.5 w-[74%] rounded-full bg-slate-900" />
              </div>
              <p className="mt-2 text-xs text-slate-500">Objectif 2500 kcal</p>
            </article>
          </div>
        </section>

        <aside className="grid gap-4">
          <article className="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-[0_14px_40px_rgba(15,23,42,0.06)]">
            <p className="text-sm font-semibold text-slate-900">Activité</p>
            <div className="mt-4 grid grid-cols-2 gap-3">
              <div className="rounded-2xl bg-slate-50 p-4">
                <p className="text-xs text-slate-500">Pas</p>
                <p className="mt-1 text-xl font-semibold text-slate-900">8 420</p>
              </div>
              <div className="rounded-2xl bg-slate-50 p-4">
                <p className="text-xs text-slate-500">Sport</p>
                <p className="mt-1 text-xl font-semibold text-slate-900">42 min</p>
              </div>
            </div>
          </article>

          <article className="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-[0_14px_40px_rgba(15,23,42,0.06)]">
              <div className="flex items-center justify-between">
                <p className="text-sm font-semibold text-slate-900">Exemple de recette</p>
                <Link
                  href="/dashboard/recettes"
                  className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-600 transition hover:border-slate-300 hover:bg-slate-50"
                >
                  Ouvrir l&apos;onglet
                </Link>
                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={showPreviousRecipe}
                    aria-label="Plat precedent"
                    className="grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-600 transition hover:border-slate-300 hover:bg-slate-50"
                  >
                    ←
                  </button>
                  <button
                    type="button"
                    onClick={showNextRecipe}
                    aria-label="Plat suivant"
                    className="grid h-9 w-9 place-items-center rounded-xl border border-slate-200 text-slate-600 transition hover:border-slate-300 hover:bg-slate-50"
                  >
                    →
                  </button>
                </div>
              </div>

              <div className="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
                <div className="relative aspect-[16/10] w-full">
                  <Image
                    src={activeRecipe.image}
                    alt={activeRecipe.title}
                    fill
                    sizes="(max-width: 1024px) 100vw, 33vw"
                    className="object-cover"
                    priority
                  />
                </div>

                <div className="p-4">
                  <div className="flex items-center justify-between gap-3">
                    <p className="text-base font-semibold text-slate-900">{activeRecipe.title}</p>
                    <span className="rounded-full border border-lime-200 bg-lime-50 px-2.5 py-1 text-xs font-medium text-lime-700">
                      {activeRecipe.kcal}
                    </span>
                  </div>
                  <p className="mt-2 text-sm leading-6 text-slate-600">{activeRecipe.description}</p>
                </div>
              </div>
          </article>
        </aside>
      </div>

      <section className="mt-4 rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-[0_14px_40px_rgba(15,23,42,0.06)]">
        <div className="grid gap-3 sm:grid-cols-4">
          <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
            <p className="text-xs text-slate-500">Petit-déj</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">420 kcal</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
            <p className="text-xs text-slate-500">Déjeuner</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">680 kcal</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
            <p className="text-xs text-slate-500">Collation</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">220 kcal</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
            <p className="text-xs text-slate-500">Dîner</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">540 kcal</p>
          </div>
        </div>
      </section>
    </>
  );
}
