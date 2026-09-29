"use client";

import { useState, type ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiGet, apiPost, getErrorMessage } from "@/lib/api-client";
import { formatDate, formatNumber } from "@/lib/format";
import type { DataEnvelope } from "@/lib/types/api";
import { MagasinsTab } from "@/components/admin/magasins-tab";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card, CardHeader } from "@/components/ui/card";
import { Field, SelectField } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
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
  offre: OffreCle;
  offre_libelle: string;
  offre_expire_le: string | null;
  /** Son offre à elle, échéance appliquée : c'est le serveur qui tranche, pas l'horloge du navigateur. */
  offre_valide: OffreCle;
  /** Ce dont le compte dispose vraiment : la meilleure entre la sienne et celle de son foyer. */
  offre_effective: OffreCle;
  offre_effective_libelle: string;
  /** Combien de comptes dépendent de l'offre de celui-ci, parce qu'il porte leur foyer. */
  foyer_membres_couverts: number;
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

type OffreCle = "gratuit" | "complet" | "foyer";

type Onglet = "tableau" | "comptes" | "moderation" | "codes" | "magasins" | "journal";

const ONGLETS: { cle: Onglet; libelle: string }[] = [
  { cle: "tableau", libelle: "Tableau de bord" },
  { cle: "comptes", libelle: "Comptes" },
  { cle: "moderation", libelle: "Modération" },
  { cle: "codes", libelle: "Codes d’accès" },
  { cle: "magasins", libelle: "Magasins" },
  { cle: "journal", libelle: "Journal" },
];

const OFFRES: { cle: OffreCle; libelle: string }[] = [
  { cle: "gratuit", libelle: "Gratuit" },
  { cle: "complet", libelle: "Complet" },
  { cle: "foyer", libelle: "Foyer" },
];

/** Miroir de Offre::rang() : une offre n’en couvre une autre que si son rang est plus haut. */
const RANG_OFFRE: Record<OffreCle, number> = { gratuit: 0, complet: 1, foyer: 2 };

// Le journal garde des identifiants stables : les traduire ici, et non en base, permet de
// reformuler un libellé sans toucher aux lignes déjà écrites.
const CIBLES_JOURNAL: Record<string, string> = {
  user: "compte",
  food: "aliment",
  recipe: "recette",
  code_acces: "code d’accès",
};

const ACTIONS_JOURNAL: Record<string, string> = {
  suspendre_compte: "Suspension d’un compte",
  lever_suspension: "Levée d’une suspension",
  changer_offre: "Changement d’offre",
  masquer_aliment: "Masquage d’un aliment",
  demasquer_aliment: "Rétablissement d’un aliment",
  masquer_recette: "Masquage d’une recette",
  demasquer_recette: "Rétablissement d’une recette",
  creer_code_acces: "Création d’un code d’accès",
  revoquer_code_acces: "Révocation d’un code d’accès",
};

/**
 * L’espace d’administration vit hors du tableau de bord, donc hors de `DashboardShell` : c’est
 * à lui de poser le fond opaque de l’application, faute de quoi il s’affiche à nu sur le
 * dégradé du body.
 */
function CadreAdmin({ className, children }: { className?: string; children: ReactNode }) {
  return (
    <main className="min-h-screen bg-[#f3faec] p-4 text-[#0b3f2f] sm:p-6">
      <div className={className}>{children}</div>
    </main>
  );
}

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
      <CadreAdmin className="mx-auto w-full max-w-5xl">
        <SkeletonCard lines={5} />
      </CadreAdmin>
    );
  }

  if (stats.isError) {
    // Sans droits, Laravel répond « Introuvable. » : on n'en dit pas plus.
    return (
      <CadreAdmin className="mx-auto w-full max-w-lg py-10">
        <div className="rounded-[1.5rem] border border-emerald-100 bg-white p-8 text-center shadow-[0_20px_60px_rgba(15,23,42,0.08)]">
          <h1 className="text-xl font-semibold text-slate-900">Page introuvable</h1>
          <p className="mt-2 text-sm text-slate-600">Cette adresse ne correspond à rien.</p>
        </div>
      </CadreAdmin>
    );
  }

  return (
    <CadreAdmin className="mx-auto w-full max-w-6xl space-y-6">
      <SectionHeader
        eyebrow="Administration"
        level="page"
        title="Mavi’oh — administration"
        subtitle="Comptes, contenus publiés et statistiques. Aucune donnée de santé n’est accessible ici."
      />

      <div
        role="tablist"
        aria-label="Sections"
        className="flex flex-wrap gap-1 rounded-2xl border border-slate-200 bg-white p-1"
      >
        {ONGLETS.map((item) => (
          <button
            key={item.cle}
            type="button"
            role="tab"
            aria-selected={onglet === item.cle}
            onClick={() => setOnglet(item.cle)}
            className={`min-h-10 rounded-xl px-4 py-2 text-sm font-medium transition ${
              onglet === item.cle
                ? "bg-emerald-700 text-white shadow-sm"
                : "text-slate-600 hover:bg-slate-50 hover:text-slate-900"
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
      {onglet === "magasins" ? <MagasinsTab /> : null}
      {onglet === "journal" ? <Journal /> : null}
    </CadreAdmin>
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
  const [cible, setCible] = useState<AdminUser | null>(null);

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

  const changerOffre = useMutation({
    mutationFn: ({ id, ...corps }: { id: number; offre: OffreCle; duree_jours?: number; motif: string }) =>
      apiPost(`/admin/users/${id}/offre`, corps),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["admin"] });
      setCible(null);
      toast.success("Offre mise à jour.");
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
          <table className="w-full min-w-[58rem] border-collapse text-sm">
            <thead>
              <tr>
                {["Compte", "État", "Offre", "Conditions", "Inscrit le", ""].map((entete) => (
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
                  <td className="border-b border-slate-200 px-3 py-2.5">
                    <OffreCellule compte={compte} />
                  </td>
                  <td className="border-b border-slate-200 px-3 py-2.5 text-xs text-slate-600">
                    {compte.cgu_version ?? "—"}
                  </td>
                  <td className="border-b border-slate-200 px-3 py-2.5 text-xs text-slate-600">
                    {compte.created_at ? compte.created_at.slice(0, 10) : "—"}
                  </td>
                  <td className="border-b border-slate-200 px-3 py-2.5">
                    <div className="flex flex-wrap justify-end gap-1.5">
                      <Button size="sm" variant="secondary" onClick={() => setCible(compte)}>
                        Changer l’offre
                      </Button>
                      {compte.role === "administrateur" ? null : (
                        <Button size="sm" variant={compte.suspendu_le ? "secondary" : "danger"} onClick={() => basculer(compte)}>
                          {compte.suspendu_le ? "Rétablir" : "Suspendre"}
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {cible ? (
        <ModaleOffre
          key={cible.id}
          compte={cible}
          envoiEnCours={changerOffre.isPending}
          onFermer={() => setCible(null)}
          onValider={(corps) => changerOffre.mutate({ id: cible.id, ...corps })}
        />
      ) : null}
    </Card>
  );
}

/**
 * Ce qu’un compte a souscrit, et ce dont il dispose réellement : les deux divergent pour le
 * membre d’un foyer, couvert par l’offre du propriétaire. N’afficher que la colonne brute
 * ferait croire à tort que cette personne est bridée.
 */
function OffreCellule({ compte }: { compte: AdminUser }) {
  // L’échéance se joue à l’heure près, et le navigateur ne voit qu’une date : recalculer ici
  // annoncerait une offre encore active des heures après que le serveur l’a coupée. C’est donc
  // lui qui tranche, avec offre_valide.
  const expiree = compte.offre_expire_le !== null && compte.offre_valide !== compte.offre;
  // L’offre effective n’est pas toujours la meilleure des deux : une échéance passée la fait
  // retomber SOUS l’offre souscrite. Comparer les deux clés brutes annoncerait donc « couvert
  // par le foyer » à un compte échu qui n’a même pas de foyer.
  const couvertParLeFoyer =
    compte.dans_un_foyer && RANG_OFFRE[compte.offre_effective] > RANG_OFFRE[compte.offre_valide];

  return (
    <>
      <p className="text-slate-900">
        <span className="font-medium">{compte.offre_libelle}</span>
        {expiree ? <span className="text-rose-700"> (expirée)</span> : null}
        {couvertParLeFoyer ? (
          <span className="text-slate-600"> · couvert par le foyer ({compte.offre_effective_libelle})</span>
        ) : null}
      </p>
      <p className="mt-0.5 text-xs text-slate-500">
        {compte.offre_expire_le
          ? `${expiree ? "expirée le" : "jusqu’au"} ${formatDate(compte.offre_expire_le)}`
          : "sans échéance"}
      </p>
      {compte.foyer_membres_couverts > 0 ? (
        <p className="mt-0.5 text-xs text-slate-500">
          couvre {compte.foyer_membres_couverts} membre{compte.foyer_membres_couverts > 1 ? "s" : ""} de son foyer
        </p>
      ) : null}
    </>
  );
}

/**
 * Jours restants avant une échéance, arrondis au jour supérieur : reposer la même durée
 * reconduit la même date, et une échéance déjà passée ne propose rien.
 */
function joursRestants(echeance: string | null): string {
  if (echeance === null) return "";

  const jours = Math.ceil((Date.parse(echeance) - Date.now()) / 86_400_000);

  return jours >= 1 && jours <= 3650 ? String(jours) : "";
}

/**
 * Poser une offre à la main demande trois réponses : laquelle, pour combien de temps, et
 * pourquoi. Une invite du navigateur n’en accepte qu’une, d’où cette boîte de dialogue.
 */
function ModaleOffre({
  compte,
  envoiEnCours,
  onFermer,
  onValider,
}: {
  compte: AdminUser;
  envoiEnCours: boolean;
  onFermer: () => void;
  onValider: (corps: { offre: OffreCle; duree_jours?: number; motif: string }) => void;
}) {
  // Préremplie avec ce qu’il reste à courir : rouvrir la boîte pour corriger un détail ne doit
  // pas transformer une offre datée en abonnement à vie par simple omission.
  const [offre, setOffre] = useState<OffreCle>(compte.offre);
  const [duree, setDuree] = useState(() => joursRestants(compte.offre_expire_le));
  const [motif, setMotif] = useState("");

  // L’offre gratuite n’expire pas : la durée est neutralisée pendant le rendu, et non remise à
  // zéro dans un effet — React 19 interdit d’y écrire un état.
  const sansEcheance = offre === "gratuit";
  const dureeSaisie = sansEcheance ? "" : duree;
  const jours = Number(dureeSaisie.trim());
  const dureeValide = dureeSaisie.trim() === "" || (Number.isInteger(jours) && jours >= 1 && jours <= 3650);
  const motifValide = motif.trim().length >= 3;

  function envoyer() {
    if (!motifValide || !dureeValide) return;
    onValider({
      offre,
      ...(dureeSaisie.trim() === "" ? {} : { duree_jours: jours }),
      motif: motif.trim(),
    });
  }

  return (
    <Modal
      open
      onClose={onFermer}
      locked={envoiEnCours}
      title="Changer l’offre"
      description={`${compte.name} — ${compte.email} · aujourd’hui : ${compte.offre_libelle}, ${
        compte.offre_expire_le ? `jusqu’au ${formatDate(compte.offre_expire_le)}` : "sans échéance"
      }`}
      footer={
        <>
          <Button variant="secondary" onClick={onFermer} disabled={envoiEnCours}>
            Annuler
          </Button>
          <Button onClick={envoyer} loading={envoiEnCours} disabled={!motifValide || !dureeValide}>
            Appliquer
          </Button>
        </>
      }
    >
      <form
        className="space-y-3"
        onSubmit={(event) => {
          event.preventDefault();
          envoyer();
        }}
      >
        <SelectField label="Offre" value={offre} onChange={(event) => setOffre(event.target.value as OffreCle)}>
          {OFFRES.map((item) => (
            <option key={item.cle} value={item.cle}>
              {item.libelle}
            </option>
          ))}
        </SelectField>

        <Field
          label="Durée (jours)"
          type="number"
          min="1"
          max="3650"
          value={dureeSaisie}
          disabled={sansEcheance}
          onChange={(event) => setDuree(event.target.value)}
          hint={
            sansEcheance
              ? "L’offre gratuite n’expire pas."
              : compte.offre_expire_le && dureeSaisie.trim() === ""
                ? `Vide = supprimer l’échéance du ${formatDate(compte.offre_expire_le)} : l’offre n’aura plus de fin.`
                : "Vide = sans échéance. Sinon, de 1 à 3650 jours à compter d’aujourd’hui."
          }
          error={dureeValide ? undefined : "Entre 1 et 3650 jours."}
        />

        {compte.foyer_membres_couverts > 0 && RANG_OFFRE[offre] < RANG_OFFRE["foyer"] ? (
          <Banner tone="warning">
            {compte.foyer_membres_couverts > 1
              ? `Ce compte couvre ${compte.foyer_membres_couverts} autres personnes de son foyer. Lui retirer l’offre Foyer leur retire aussi, à l’instant, les fonctions payantes qu’elles en tiennent.`
              : "Ce compte couvre une autre personne de son foyer. Lui retirer l’offre Foyer lui retire aussi, à l’instant, les fonctions payantes qu’elle en tient."}
          </Banner>
        ) : null}

        <Field
          label="Motif"
          required
          value={motif}
          onChange={(event) => setMotif(event.target.value)}
          placeholder="Pourquoi ce changement ?"
          hint="Consigné au journal avec ton adresse. Trois caractères au minimum."
        />
      </form>
    </Modal>
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
              <span className="font-medium">{ACTIONS_JOURNAL[ligne.action] ?? ligne.action}</span>
              {ligne.cible_libelle ? ` — ${ligne.cible_libelle}` : ""}
            </p>
            <p className="mt-0.5 text-xs text-slate-500">
              {ligne.admin_email} · {ligne.created_at?.slice(0, 19).replace("T", " ")} ·{" "}
              {CIBLES_JOURNAL[ligne.cible_type] ?? ligne.cible_type}
              {ligne.cible_id !== null ? ` #${ligne.cible_id}` : ""}
            </p>
            <p className="mt-1 text-xs text-slate-600">Motif : {ligne.motif}</p>
          </li>
        ))}
      </ul>
    </Card>
  );
}
