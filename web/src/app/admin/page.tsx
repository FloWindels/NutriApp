"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiGet, apiPost, getErrorMessage } from "@/lib/api-client";
import { formatNumber } from "@/lib/format";
import type { DataEnvelope } from "@/lib/types/api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { Field } from "@/components/ui/field";
import { Overline, SectionHeader } from "@/components/ui/section-header";
import { SkeletonCard } from "@/components/ui/skeleton";
import { StatCard } from "@/components/ui/stat-card";
import { useToast } from "@/components/ui/toast";

/**
 * Espace d'administration.
 *
 * Il vit sur une racine séparée du tableau de bord, n'apparaît dans aucun menu, et n'est
 * accessible qu'en connaissant l'adresse. Il faut être lucide sur ce que cela vaut : le site
 * ne protège rien. Le jeton est dans le navigateur, aucune vérification n'a lieu côté serveur
 * Next, et la seule barrière réelle est le 404 que renvoie Laravel à quiconque n'est pas
 * administrateur. Cette page se contente donc de sonder l'API avant d'afficher quoi que ce
 * soit : sans droits, elle ne montre rien de plus qu'une page inexistante.
 */

type Stats = {
  comptes: {
    total: number;
    nouveaux_7j: number;
    nouveaux_30j: number;
    avec_profil: number;
    suspendus: number;
    par_version_cgu: Record<string, number>;
  };
  activite: { actifs_7j: number; actifs_30j: number; fenetre_max_jours: number };
  usage: {
    repas_30j: number;
    repas_par_jour: Record<string, number>;
    seances_par_statut: Record<string, number>;
  };
  contenus: {
    aliments_crees: number;
    aliments_masques: number;
    recettes: number;
    recettes_publiques: number;
    recettes_masquees: number;
  };
  ia: { seances_par_origine: Record<string, number>; modeles: Record<string, number> };
};

type AdminUser = {
  id: number;
  name: string;
  email: string;
  email_verifie: boolean;
  role: string;
  dans_un_foyer: boolean;
  suspendu_le: string | null;
  suspension_motif: string | null;
  cgu_version: string | null;
  consentement_sante: boolean;
  created_at: string | null;
};

type ModFood = {
  id: number;
  name: string;
  brand: string | null;
  barcode: string | null;
  masque_le: string | null;
  masque_motif: string | null;
};

type ModRecipe = {
  id: number;
  title: string;
  description: string | null;
  masque_le: string | null;
  masque_motif: string | null;
};

type JournalLigne = {
  id: number;
  admin_email: string;
  action: string;
  cible_type: string;
  cible_id: number | null;
  cible_libelle: string | null;
  motif: string;
  created_at: string | null;
};

type Onglet = "tableau" | "comptes" | "moderation" | "codes" | "journal";

const ONGLETS: { cle: Onglet; libelle: string }[] = [
  { cle: "tableau", libelle: "Tableau de bord" },
  { cle: "comptes", libelle: "Comptes" },
  { cle: "moderation", libelle: "Modération" },
  { cle: "codes", libelle: "Codes d’accès" },
  { cle: "journal", libelle: "Journal" },
];

export default function AdminPage() {
  const [onglet, setOnglet] = useState<Onglet>("tableau");

  // Sonde : tant que l'API n'a pas confirmé les droits, la page n'affiche rien d'utile.
  const stats = useQuery({
    queryKey: ["admin", "stats"],
    queryFn: () => apiGet<DataEnvelope<Stats>>("/admin/stats"),
    retry: false,
  });

  if (stats.isPending) {
    return (
      <main className="mx-auto max-w-5xl p-6">
        <SkeletonCard lines={5} />
      </main>
    );
  }

  if (stats.isError) {
    // Sans droits, Laravel répond « Introuvable. » : on n'en dit pas plus.
    return (
      <main className="mx-auto max-w-lg p-10 text-center">
        <h1 className="text-xl font-semibold text-slate-900">Page introuvable</h1>
        <p className="mt-2 text-sm text-slate-600">Cette adresse ne correspond à rien.</p>
      </main>
    );
  }

  return (
    <main className="mx-auto max-w-6xl space-y-5 p-4 sm:p-6">
      <SectionHeader
        eyebrow="Administration"
        level="page"
        title="Mavi’oh — administration"
        subtitle="Comptes, contenus publiés et statistiques. Aucune donnée de santé n’est accessible ici."
      />

      <div className="flex flex-wrap gap-1.5" role="tablist" aria-label="Sections">
        {ONGLETS.map((item) => (
          <button
            key={item.cle}
            type="button"
            role="tab"
            aria-selected={onglet === item.cle}
            onClick={() => setOnglet(item.cle)}
            className={`h-9 rounded-full border px-3 text-sm font-medium transition ${
              onglet === item.cle
                ? "border-slate-900 bg-slate-900 text-white"
                : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50"
            }`}
          >
            {item.libelle}
          </button>
        ))}
      </div>

      {onglet === "tableau" ? <TableauDeBord stats={stats.data.data} /> : null}
      {onglet === "comptes" ? <Comptes /> : null}
      {onglet === "moderation" ? <Moderation /> : null}
      {onglet === "codes" ? <Codes /> : null}
      {onglet === "journal" ? <Journal /> : null}
    </main>
  );
}

function TableauDeBord({ stats }: { stats: Stats }) {
  const { comptes, activite, usage, contenus, ia } = stats;

  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Comptes" value={comptes.total} caption={`${comptes.nouveaux_7j} cette semaine`} />
        <StatCard
          label="Actifs (30 j)"
          value={activite.actifs_30j}
          caption={`${activite.actifs_7j} sur 7 jours`}
        />
        <StatCard label="Profils complétés" value={comptes.avec_profil} />
        <StatCard label="Comptes suspendus" value={comptes.suspendus} tone={comptes.suspendus > 0 ? "amber" : "slate"} />
      </div>

      <Banner tone="info">
        L’activité se mesure sur les jetons de connexion, qui sont purgés au bout de{" "}
        {activite.fenetre_max_jours} jours : au-delà de cette fenêtre, le chiffre n’a pas de sens
        et n’est donc pas affiché.
      </Banner>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card padding="md">
          <CardHeader title="Usage" subtitle="Trente derniers jours" />
          <div className="mt-3 grid gap-3 sm:grid-cols-2">
            <StatCard label="Repas enregistrés" value={usage.repas_30j} />
            <StatCard
              label="Séances"
              value={Object.values(usage.seances_par_statut).reduce((a, b) => a + b, 0)}
            />
          </div>
          <Overline className="mt-4 mb-2">Séances par statut</Overline>
          <Repartition valeurs={usage.seances_par_statut} />
        </Card>

        <Card padding="md">
          <CardHeader title="Contenus publiés" subtitle="Ce qui est visible par autrui" />
          <div className="mt-3 grid gap-3 sm:grid-cols-2">
            <StatCard
              label="Aliments créés"
              value={contenus.aliments_crees}
              caption={`${contenus.aliments_masques} masqués`}
            />
            <StatCard
              label="Recettes publiques"
              value={contenus.recettes_publiques}
              caption={`${contenus.recettes_masquees} masquées`}
            />
          </div>
        </Card>

        <Card padding="md">
          <CardHeader title="Intelligence artificielle" subtitle="Origine des séances générées" />
          <Overline className="mt-3 mb-2">Par origine</Overline>
          <Repartition valeurs={ia.seances_par_origine} />
          {Object.keys(ia.modeles).length > 0 ? (
            <>
              <Overline className="mt-4 mb-2">Modèles utilisés</Overline>
              <Repartition valeurs={ia.modeles} />
            </>
          ) : null}
          <p className="mt-3 text-xs text-slate-500">
            Les échecs de génération ne sont pas comptés : une séance produite par repli est
            enregistrée comme « règles », sans distinguer une panne d’une absence de configuration.
          </p>
        </Card>

        <Card padding="md">
          <CardHeader
            title="Acceptation des conditions"
            subtitle="Qui doit reprendre connaissance des textes"
          />
          <Repartition valeurs={comptes.par_version_cgu} className="mt-3" />
        </Card>
      </div>
    </div>
  );
}

function Repartition({ valeurs, className }: { valeurs: Record<string, number>; className?: string }) {
  const entrees = Object.entries(valeurs);
  if (entrees.length === 0) return <p className={`text-sm text-slate-500 ${className ?? ""}`}>Aucune donnée.</p>;

  const total = entrees.reduce((somme, [, n]) => somme + n, 0);

  return (
    <ul className={`space-y-1.5 ${className ?? ""}`}>
      {entrees.map(([cle, nombre]) => (
        <li key={cle} className="flex items-center justify-between gap-3 text-sm">
          <span className="truncate text-slate-700">{cle || "non renseigné"}</span>
          <span className="shrink-0 tabular-nums text-slate-900">
            {nombre} <span className="text-slate-400">({formatNumber((nombre / total) * 100, 0)} %)</span>
          </span>
        </li>
      ))}
    </ul>
  );
}

function Comptes() {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [recherche, setRecherche] = useState("");
  const [terme, setTerme] = useState("");

  const comptes = useQuery({
    queryKey: ["admin", "users", terme],
    queryFn: () => apiGet<{ data: AdminUser[] }>("/admin/users", terme ? { q: terme } : undefined),
  });

  const action = useMutation({
    mutationFn: ({ id, suspendre, motif }: { id: number; suspendre: boolean; motif: string }) =>
      apiPost(`/admin/users/${id}/${suspendre ? "suspend" : "restore"}`, { motif }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["admin"] });
      toast.success("Compte mis à jour.");
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  function basculer(compte: AdminUser) {
    const suspendre = compte.suspendu_le === null;
    const motif = window.prompt(
      suspendre
        ? `Motif de la suspension de ${compte.email} :`
        : `Motif de la levée de suspension de ${compte.email} :`,
    );
    if (motif === null || motif.trim().length < 3) return;
    action.mutate({ id: compte.id, suspendre, motif: motif.trim() });
  }

  return (
    <Card padding="md" className="space-y-4">
      <CardHeader
        title="Comptes"
        subtitle="Nom, adresse, état. Ni journal alimentaire, ni profil de santé, ni pesées."
      />

      <form
        className="flex flex-wrap items-end gap-2"
        onSubmit={(event) => {
          event.preventDefault();
          setTerme(recherche.trim());
        }}
      >
        <div className="min-w-[14rem] flex-1">
          <Field
            label="Rechercher"
            value={recherche}
            onChange={(event) => setRecherche(event.target.value)}
            placeholder="Nom ou adresse e-mail"
          />
        </div>
        <Button type="submit" variant="secondary">
          Chercher
        </Button>
      </form>

      {comptes.isPending ? <SkeletonCard lines={4} /> : null}
      {comptes.isError ? <Banner tone="error">{getErrorMessage(comptes.error)}</Banner> : null}

      {comptes.data ? (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[46rem] border-collapse text-sm">
            <thead>
              <tr>
                {["Compte", "État", "Conditions", "Inscrit le", ""].map((entete) => (
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
              {comptes.data.data.map((compte) => (
                <tr key={compte.id} className="align-top">
                  <td className="border-b border-slate-200 px-3 py-2.5">
                    <p className="font-medium text-slate-900">{compte.name}</p>
                    <p className="text-xs text-slate-500">{compte.email}</p>
                    {compte.role !== "utilisateur" ? (
                      <p className="mt-0.5 text-xs font-medium text-violet-700">{compte.role}</p>
                    ) : null}
                  </td>
                  <td className="border-b border-slate-200 px-3 py-2.5 text-slate-700">
                    {compte.suspendu_le ? (
                      <>
                        <span className="font-medium text-rose-700">Suspendu</span>
                        <p className="text-xs text-slate-500">{compte.suspension_motif}</p>
                      </>
                    ) : (
                      "Actif"
                    )}
                  </td>
                  <td className="border-b border-slate-200 px-3 py-2.5 text-xs text-slate-600">
                    {compte.cgu_version ?? "—"}
                  </td>
                  <td className="border-b border-slate-200 px-3 py-2.5 text-xs text-slate-600">
                    {compte.created_at ? compte.created_at.slice(0, 10) : "—"}
                  </td>
                  <td className="border-b border-slate-200 px-3 py-2.5">
                    {compte.role === "administrateur" ? null : (
                      <Button size="sm" variant={compte.suspendu_le ? "secondary" : "danger"} onClick={() => basculer(compte)}>
                        {compte.suspendu_le ? "Rétablir" : "Suspendre"}
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </Card>
  );
}

function Moderation() {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [masques, setMasques] = useState(false);

  const aliments = useQuery({
    queryKey: ["admin", "moderation", "foods", masques],
    queryFn: () => apiGet<{ data: ModFood[] }>("/admin/moderation/foods", masques ? { masques: "1" } : undefined),
  });

  const recettes = useQuery({
    queryKey: ["admin", "moderation", "recipes", masques],
    queryFn: () => apiGet<{ data: ModRecipe[] }>("/admin/moderation/recipes", masques ? { masques: "1" } : undefined),
  });

  const action = useMutation({
    mutationFn: ({ type, id, masquer, motif }: { type: "foods" | "recipes"; id: number; masquer: boolean; motif: string }) =>
      apiPost(`/admin/moderation/${type}/${id}/${masquer ? "hide" : "show"}`, { motif }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["admin"] });
      toast.success("Contenu mis à jour.");
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  function basculer(type: "foods" | "recipes", id: number, libelle: string, dejaMasque: boolean) {
    const motif = window.prompt(
      dejaMasque ? `Motif du rétablissement de « ${libelle} » :` : `Motif du masquage de « ${libelle} » :`,
    );
    if (motif === null || motif.trim().length < 3) return;
    action.mutate({ type, id, masquer: !dejaMasque, motif: motif.trim() });
  }

  return (
    <div className="space-y-4">
      <Banner tone="info">
        Masquer n’efface rien : le contenu disparaît pour les autres, son auteur le conserve, et
        l’action est réversible. Chaque geste est consigné dans le journal, avec son motif.
      </Banner>

      <label className="flex items-center gap-2 text-sm text-slate-700">
        <input
          type="checkbox"
          checked={masques}
          onChange={(event) => setMasques(event.target.checked)}
          className="h-4 w-4 rounded border-slate-300 accent-emerald-700"
        />
        N’afficher que les contenus déjà masqués
      </label>

      <Card padding="md">
        <CardHeader title="Aliments créés par les utilisateurs" subtitle="Visibles sans compte, y compris par code-barres" />
        <ul className="mt-3 space-y-2">
          {aliments.data?.data.length === 0 ? <li className="text-sm text-slate-500">Aucun.</li> : null}
          {aliments.data?.data.map((aliment) => (
            <li
              key={aliment.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 px-3 py-2.5"
            >
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-slate-900">{aliment.name}</p>
                <p className="text-xs text-slate-500">
                  {[aliment.brand, aliment.barcode].filter(Boolean).join(" · ") || "—"}
                  {aliment.masque_le ? ` · masqué : ${aliment.masque_motif}` : ""}
                </p>
              </div>
              <Button
                size="sm"
                variant={aliment.masque_le ? "secondary" : "danger"}
                onClick={() => basculer("foods", aliment.id, aliment.name, aliment.masque_le !== null)}
              >
                {aliment.masque_le ? "Rétablir" : "Masquer"}
              </Button>
            </li>
          ))}
        </ul>
      </Card>

      <Card padding="md">
        <CardHeader title="Recettes publiées" />
        <ul className="mt-3 space-y-2">
          {recettes.data?.data.length === 0 ? <li className="text-sm text-slate-500">Aucune.</li> : null}
          {recettes.data?.data.map((recette) => (
            <li
              key={recette.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 px-3 py-2.5"
            >
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-slate-900">{recette.title}</p>
                <p className="truncate text-xs text-slate-500">
                  {recette.description ?? "—"}
                  {recette.masque_le ? ` · masquée : ${recette.masque_motif}` : ""}
                </p>
              </div>
              <Button
                size="sm"
                variant={recette.masque_le ? "secondary" : "danger"}
                onClick={() => basculer("recipes", recette.id, recette.title, recette.masque_le !== null)}
              >
                {recette.masque_le ? "Rétablir" : "Masquer"}
              </Button>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}

type CodeAcces = {
  id: number;
  code: string;
  offre: string;
  offre_libelle: string;
  duree_jours: number | null;
  utilisations: number;
  utilisations_max: number;
  expire_le: string | null;
  actif: boolean;
  utilisable: boolean;
  note: string | null;
};

function Codes() {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [offre, setOffre] = useState("foyer");
  const [utilisations, setUtilisations] = useState("1");
  const [duree, setDuree] = useState("");
  const [note, setNote] = useState("");

  const codes = useQuery({
    queryKey: ["admin", "codes"],
    queryFn: () => apiGet<{ data: CodeAcces[] }>("/admin/codes"),
  });

  const creer = useMutation({
    mutationFn: () =>
      apiPost<{ data: { code: string } }>("/admin/codes", {
        offre,
        utilisations_max: Number(utilisations) || 1,
        ...(duree.trim() !== "" ? { duree_jours: Number(duree) } : {}),
        ...(note.trim() !== "" ? { note: note.trim() } : {}),
      }),
    onSuccess: (reponse) => {
      queryClient.invalidateQueries({ queryKey: ["admin"] });
      setNote("");
      toast.success("Code créé.", reponse.data.code);
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const revoquer = useMutation({
    mutationFn: ({ id, motif }: { id: number; motif: string }) =>
      apiPost(`/admin/codes/${id}/revoke`, { motif }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["admin"] });
      toast.success("Code révoqué.");
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  return (
    <div className="space-y-4">
      <Banner tone="info">
        Un code ouvre l’application entière à qui le saisit dans ses paramètres. Révoquer un code
        empêche les nouvelles utilisations, sans reprendre l’accès à ceux qui s’en sont déjà
        servis de bonne foi. Chaque création est consignée au journal.
      </Banner>

      <Card padding="md" className="space-y-3">
        <CardHeader title="Créer un code" subtitle="À dicter ou à recopier : ni O/0, ni I/1, ni S/5." />
        <div className="grid gap-3 sm:grid-cols-4">
          <label className="text-sm">
            <span className="mb-1.5 block font-medium text-slate-700">Offre</span>
            <select
              value={offre}
              onChange={(event) => setOffre(event.target.value)}
              className="h-11 w-full rounded-2xl border border-slate-200 bg-white px-3"
            >
              <option value="complet">Complet</option>
              <option value="foyer">Foyer</option>
            </select>
          </label>
          <Field
            label="Utilisations"
            type="number"
            min="1"
            value={utilisations}
            onChange={(event) => setUtilisations(event.target.value)}
          />
          <Field
            label="Durée (jours)"
            type="number"
            min="1"
            value={duree}
            onChange={(event) => setDuree(event.target.value)}
            hint="Vide = sans fin"
          />
          <Field label="Note" value={note} onChange={(event) => setNote(event.target.value)} />
        </div>
        <Button type="button" onClick={() => creer.mutate()} loading={creer.isPending}>
          Créer le code
        </Button>
      </Card>

      <Card padding="md">
        <CardHeader title="Codes existants" />
        <ul className="mt-3 space-y-2">
          {codes.data?.data.length === 0 ? <li className="text-sm text-slate-500">Aucun code.</li> : null}
          {codes.data?.data.map((code) => (
            <li
              key={code.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 px-3 py-2.5"
            >
              <div className="min-w-0">
                <p className="font-mono text-sm font-semibold tracking-wider text-slate-900">{code.code}</p>
                <p className="text-xs text-slate-500">
                  {code.offre_libelle} · {code.utilisations}/{code.utilisations_max} utilisé
                  {code.duree_jours ? ` · ${code.duree_jours} jours` : " · sans fin"}
                  {code.note ? ` · ${code.note}` : ""}
                  {code.utilisable ? "" : " · inutilisable"}
                </p>
              </div>
              {code.actif ? (
                <Button
                  size="sm"
                  variant="danger"
                  onClick={() => {
                    const motif = window.prompt(`Motif de la révocation du code ${code.code} :`);
                    if (motif === null || motif.trim().length < 3) return;
                    revoquer.mutate({ id: code.id, motif: motif.trim() });
                  }}
                >
                  Révoquer
                </Button>
              ) : (
                <span className="text-xs text-slate-400">révoqué</span>
              )}
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}

function Journal() {
  const journal = useQuery({
    queryKey: ["admin", "journal"],
    queryFn: () => apiGet<{ data: JournalLigne[] }>("/admin/journal"),
  });

  return (
    <Card padding="md">
      <CardHeader
        title="Journal des actions"
        subtitle="Qui, quand, sur quoi, et pourquoi. En ajout seul : rien ne s’y efface."
      />
      {journal.isPending ? <SkeletonCard lines={4} /> : null}
      <ul className="mt-3 space-y-2">
        {journal.data?.data.length === 0 ? (
          <li className="text-sm text-slate-500">Aucune action enregistrée.</li>
        ) : null}
        {journal.data?.data.map((ligne) => (
          <li key={ligne.id} className="rounded-2xl border border-slate-200 px-3 py-2.5 text-sm">
            <p className="text-slate-900">
              <span className="font-medium">{ligne.action}</span>
              {ligne.cible_libelle ? ` — ${ligne.cible_libelle}` : ""}
            </p>
            <p className="mt-0.5 text-xs text-slate-500">
              {ligne.admin_email} · {ligne.created_at?.slice(0, 19).replace("T", " ")} ·{" "}
              {ligne.cible_type}
              {ligne.cible_id !== null ? ` #${ligne.cible_id}` : ""}
            </p>
            <p className="mt-1 text-xs text-slate-600">Motif : {ligne.motif}</p>
          </li>
        ))}
      </ul>
    </Card>
  );
}
