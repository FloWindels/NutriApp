import Link from "next/link";
import type { ReactNode } from "react";
import { OPERATEUR, estIncomplet } from "@/lib/legal/operateur";
import { dateVersion } from "@/lib/legal/versions";

/**
 * Gabarit commun aux documents légaux.
 *
 * Ces pages doivent rester accessibles en permanence et sans compte : elles ne dépendent donc
 * d'aucun appel à l'API, et sont rendues statiquement.
 */

export const DOCUMENTS = [
  { href: "/mentions-legales", titre: "Mentions légales" },
  { href: "/cgu", titre: "Conditions générales d’utilisation" },
  { href: "/confidentialite", titre: "Politique de confidentialité" },
  { href: "/cookies", titre: "Stockage local et cookies" },
] as const;

export function LegalPage({
  titre,
  version,
  resume,
  children,
}: {
  titre: string;
  version: string;
  resume?: ReactNode;
  children: ReactNode;
}) {
  const manquants = estIncomplet();

  return (
    <main className="mx-auto min-h-screen w-full max-w-3xl px-4 py-10 sm:px-6">
      <Link href="/" className="text-sm font-medium text-emerald-800 hover:underline">
        ← {OPERATEUR.service}
      </Link>

      <h1 className="mt-4 text-3xl font-semibold tracking-tight text-slate-950">{titre}</h1>
      <p className="mt-1.5 text-sm text-slate-500">
        Version du {dateVersion(version)} · {OPERATEUR.service}
      </p>

      {manquants.length > 0 ? (
        <div className="mt-6 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <p className="font-semibold">Document incomplet — à ne pas publier en l’état.</p>
          <p className="mt-1">
            Les informations suivantes doivent être renseignées dans{" "}
            <code className="rounded bg-amber-100 px-1">web/src/lib/legal/operateur.ts</code> :{" "}
            {manquants.join(", ")}.
          </p>
        </div>
      ) : null}

      {resume ? (
        <div className="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-950">
          {resume}
        </div>
      ) : null}

      <div className="legal mt-8 space-y-6 text-[15px] leading-7 text-slate-700">{children}</div>

      <nav className="mt-12 border-t border-slate-200 pt-6">
        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
          Autres documents
        </p>
        <ul className="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm">
          {DOCUMENTS.filter((doc) => doc.titre !== titre).map((doc) => (
            <li key={doc.href}>
              <Link href={doc.href} className="text-emerald-800 hover:underline">
                {doc.titre}
              </Link>
            </li>
          ))}
        </ul>
      </nav>
    </main>
  );
}

export function Article({ numero, titre, children }: { numero: string; titre: string; children: ReactNode }) {
  return (
    <section className="space-y-3">
      <h2 className="text-lg font-semibold text-slate-950">
        {numero}. {titre}
      </h2>
      {children}
    </section>
  );
}

/** Tableau à deux colonnes, utilisé pour les traitements, les durées et les destinataires. */
export function Tableau({
  entetes,
  lignes,
}: {
  entetes: string[];
  lignes: ReactNode[][];
}) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[34rem] border-collapse text-sm">
        <thead>
          <tr>
            {entetes.map((entete) => (
              <th
                key={entete}
                className="border-b border-slate-300 px-3 py-2 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500"
              >
                {entete}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {lignes.map((ligne, index) => (
            <tr key={index} className="align-top">
              {ligne.map((cellule, colonne) => (
                <td key={colonne} className="border-b border-slate-200 px-3 py-2.5 text-slate-700">
                  {cellule}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Valeur issue du fichier d'identité, mise en évidence si elle n'a pas été renseignée. */
export function Valeur({ children }: { children: string | null }) {
  if (children === null) return <span className="text-slate-500">non applicable</span>;
  if (children === "À COMPLÉTER") {
    return <span className="rounded bg-amber-100 px-1 font-semibold text-amber-900">À COMPLÉTER</span>;
  }
  return <>{children}</>;
}
