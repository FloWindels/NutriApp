import type { Metadata } from "next";
import Link from "next/link";
import { OPERATEUR } from "@/lib/legal/operateur";
import { LegalFooter } from "@/components/legal/legal-footer";

export const metadata: Metadata = {
  title: "Offres — Mavi’oh",
  description: "Le suivi de base est gratuit. Le stock, le foyer et l’IA font partie des offres payantes.",
};

/**
 * Page des offres, publique.
 *
 * Aucun paiement n'est branché : les boutons ne mènent nulle part, et la page le dit au lieu de
 * faire semblant. C'est aussi ici qu'on explique qu'un code d'invitation existe.
 */

type Offre = {
  cle: string;
  nom: string;
  accroche: string;
  mensuel: number;
  annuel: number;
  inclus: string[];
  exclus?: string[];
  vedette?: boolean;
};

const OFFRES: Offre[] = [
  {
    cle: "gratuit",
    nom: "Gratuit",
    accroche: "Le suivi, sans limite de temps et sans carte bancaire.",
    mensuel: 0,
    annuel: 0,
    inclus: [
      "Journal des repas, illimité",
      "Recherche d’aliments et code-barres, sans quota",
      "Tes propres recettes",
      "Objectifs calculés et suivi du poids",
      "Suivi sportif : calendrier, activités et calories rendues à ton budget",
      "Coach du jour : conseils adaptés à ta journée",
      "Export et suppression de tes données",
    ],
    exclus: [
      "Stock et anti-gaspillage",
      "Liste de courses",
      "Planificateur",
      "Coach sportif : séances construites pour toi",
      "Mode foyer",
      "Intelligence artificielle",
    ],
  },
  {
    cle: "complet",
    nom: "Complet",
    accroche: "Tout ce qui fait gagner du temps et évite de jeter.",
    mensuel: 4.99,
    annuel: 39.99,
    vedette: true,
    inclus: [
      "Tout le gratuit",
      "Stock du frigo, du congélateur et des placards",
      "Alertes de péremption et anti-gaspillage",
      "Liste de courses, alimentée par le stock",
      "Planificateur de la semaine",
      "Coach sportif : séances et semaines construites pour toi",
      "Régime reconnu et son évaluation",
      "IA : séances, recettes depuis ton stock, photo d’assiette",
    ],
  },
  {
    cle: "foyer",
    nom: "Foyer",
    accroche: "La même chose, pour toute la maison.",
    mensuel: 7.99,
    annuel: 59.99,
    inclus: [
      "Tout le Complet, pour chaque membre",
      `Jusqu’à ${OPERATEUR.service === "Mavi’oh" ? 5 : 5} personnes`,
      "Stock et liste de courses partagés",
      "Repas communs avec portions adaptées à chacun",
      "Une seule offre couvre tout le ménage",
    ],
  },
];

function prix(valeur: number): string {
  return valeur === 0 ? "Gratuit" : valeur.toFixed(2).replace(".", ",") + " €";
}

export default function OffresPage() {
  return (
    <main className="mx-auto min-h-screen w-full max-w-5xl px-4 py-10 sm:px-6">
      <Link href="/" className="text-sm font-medium text-emerald-800 hover:underline">
        ← {OPERATEUR.service}
      </Link>

      <h1 className="mt-4 text-3xl font-semibold tracking-tight text-slate-950">Les offres</h1>
      <p className="mt-2 max-w-2xl text-[15px] leading-7 text-slate-600">
        Le suivi de ce que tu manges est gratuit, pour de bon : pas de période d’essai qui
        s’arrête, pas de quota sur la recherche d’aliments, et le coach du jour est compris.
        Enregistrer tes activités sportives et tenir ton calendrier aussi. Ce qui se paie,
        c’est ce qui travaille à ta place — construire une séance, tenir un stock, faire les
        courses d’une maison entière.
      </p>

      <div className="mt-8 grid gap-4 lg:grid-cols-3">
        {OFFRES.map((offre) => (
          <section
            key={offre.cle}
            className={`flex flex-col rounded-[1.5rem] border bg-white p-5 ${
              offre.vedette ? "border-emerald-400 shadow-[0_18px_40px_rgba(16,122,87,0.12)]" : "border-slate-200"
            }`}
          >
            {offre.vedette ? (
              <p className="mb-2 inline-flex w-fit rounded-full bg-emerald-700 px-2.5 py-1 text-xs font-semibold text-white">
                Le plus choisi
              </p>
            ) : null}

            <h2 className="text-xl font-semibold text-slate-950">{offre.nom}</h2>
            <p className="mt-1 text-sm text-slate-600">{offre.accroche}</p>

            <p className="mt-4 text-3xl font-semibold text-slate-950">
              {prix(offre.mensuel)}
              {offre.mensuel > 0 ? <span className="text-base font-normal text-slate-500"> / mois</span> : null}
            </p>
            {offre.annuel > 0 ? (
              <p className="mt-1 text-sm text-slate-500">
                ou {prix(offre.annuel)} par an —{" "}
                <span className="font-medium text-emerald-800">
                  {Math.round((1 - offre.annuel / (offre.mensuel * 12)) * 100)} % d’économie
                </span>
              </p>
            ) : null}

            <ul className="mt-4 flex-1 space-y-1.5 text-sm text-slate-700">
              {offre.inclus.map((ligne) => (
                <li key={ligne} className="flex gap-2">
                  <span aria-hidden="true" className="text-emerald-700">✓</span>
                  <span>{ligne}</span>
                </li>
              ))}
              {offre.exclus?.map((ligne) => (
                <li key={ligne} className="flex gap-2 text-slate-400">
                  <span aria-hidden="true">—</span>
                  <span>{ligne}</span>
                </li>
              ))}
            </ul>

            <div className="mt-5">
              {offre.mensuel === 0 ? (
                <Link
                  href="/register"
                  className="inline-flex h-11 w-full items-center justify-center rounded-2xl border border-emerald-700 bg-emerald-700 px-4 text-sm font-medium text-white transition hover:bg-emerald-800"
                >
                  Créer un compte
                </Link>
              ) : (
                <p className="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-center text-xs text-slate-600">
                  Pas encore souscriptible en ligne
                </p>
              )}
            </div>
          </section>
        ))}
      </div>

      <section className="mt-8 rounded-[1.5rem] border border-sky-200 bg-sky-50 p-5 text-sm leading-6 text-sky-950">
        <h2 className="text-base font-semibold">Comment y accéder aujourd’hui</h2>
        <p className="mt-2">
          Aucun paiement n’est encore en place : les offres payantes ne peuvent pas être
          souscrites en ligne, et cette page le dit plutôt que d’ouvrir un formulaire qui ne
          mènerait nulle part. En attendant, l’accès complet s’ouvre avec un{" "}
          <strong>code d’invitation</strong> remis par l’éditeur. Si tu en as reçu un, crée ton
          compte puis saisis-le dans{" "}
          <Link href="/dashboard/settings" className="font-medium underline">
            tes paramètres
          </Link>
          .
        </p>
      </section>

      <p className="mt-6 text-xs text-slate-500">
        Prix indiqués toutes taxes comprises. Les tarifs affichés sont indicatifs et pourront
        évoluer avant l’ouverture des paiements.
      </p>

      <LegalFooter className="mt-10" />
    </main>
  );
}
