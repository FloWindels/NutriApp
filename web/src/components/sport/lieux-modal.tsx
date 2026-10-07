"use client";

import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Chip, ChipGroup, Switch } from "@/components/sport/chips";
import {
  MATERIEL_CATEGORIE_LABELS,
  MATERIEL_CATEGORIE_ORDER,
  resumeMateriel,
  sportApi,
  useMaterielCatalogue,
  useSportLieux,
  useSportVocab,
} from "@/components/sport/sport-api";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { Field } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { Pill } from "@/components/ui/pill";
import { SkeletonList } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { getErrorMessage, isApiError } from "@/lib/api-client";
import { queryKeys } from "@/lib/query-keys";
import type { LieuEntrainement, LieuEntrainementInput, Materiel, SportLieu } from "@/lib/types/api";

/**
 * « Mes lieux » (docs/SPORT_V2.md §1 et §6) : chaque lieu d’entraînement a son matériel, et la
 * séance se génère pour un lieu. Liste, création / modification, lieu par défaut, suppression.
 */

export type LieuxModalProps = {
  open: boolean;
  onClose: () => void;
  /** Ouvre directement le formulaire de création (« + Ajouter un lieu »). */
  startWithCreate?: boolean;
  /** Appelé après une création ou une modification réussie. */
  onSaved?: (lieu: LieuEntrainement) => void;
};

type Vue = { mode: "liste" } | { mode: "edition"; lieu: LieuEntrainement | null };

export function LieuxModal({ open, onClose, startWithCreate = false, onSaved }: LieuxModalProps) {
  // Le contenu est remonté à chaque ouverture : le formulaire repart propre.
  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Mes lieux"
      description="Indique le matériel disponible à chaque endroit : la séance s’adapte au lieu choisi."
      size="lg"
    >
      {open ? <LieuxContenu startWithCreate={startWithCreate} onSaved={onSaved} onClose={onClose} /> : null}
    </Modal>
  );
}

function LieuxContenu({
  startWithCreate,
  onSaved,
  onClose,
}: {
  startWithCreate: boolean;
  onSaved?: (lieu: LieuEntrainement) => void;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const { success } = useToast();
  const lieuxQuery = useSportLieux();
  const { libelle } = useMaterielCatalogue();
  const [vue, setVue] = useState<Vue>(startWithCreate ? { mode: "edition", lieu: null } : { mode: "liste" });
  const [aSupprimer, setASupprimer] = useState<LieuEntrainement | null>(null);
  const [banner, setBanner] = useState<string | null>(null);

  async function rafraichir() {
    await queryClient.invalidateQueries({ queryKey: queryKeys.sport.lieux });
  }

  const parDefaut = useMutation({
    mutationFn: (lieu: LieuEntrainement) =>
      sportApi.updateLieu(lieu.id, { nom: lieu.nom, type: lieu.type, materiel: lieu.materiel, par_defaut: true }),
    onSuccess: async (response) => {
      await rafraichir();
      success(`« ${response.data.nom} » est ton lieu par défaut.`);
    },
    onError: (error) => setBanner(getErrorMessage(error)),
  });

  if (vue.mode === "edition") {
    return (
      <LieuForm
        lieu={vue.lieu}
        premier={(lieuxQuery.data?.length ?? 0) === 0}
        onCancel={() => (startWithCreate && !vue.lieu ? onClose() : setVue({ mode: "liste" }))}
        onSaved={async (lieu) => {
          await rafraichir();
          success(vue.lieu ? "Lieu mis à jour." : "Lieu ajouté.");
          onSaved?.(lieu);
          if (startWithCreate && !vue.lieu) onClose();
          else setVue({ mode: "liste" });
        }}
      />
    );
  }

  const lieux = lieuxQuery.data ?? [];

  return (
    <div className="space-y-4">
      {banner ? (
        <Banner tone="error" onClose={() => setBanner(null)}>
          {banner}
        </Banner>
      ) : null}

      {lieuxQuery.isPending ? <SkeletonList rows={2} /> : null}

      {lieuxQuery.isError ? (
        <Banner tone="error" title="Impossible de charger tes lieux">
          {getErrorMessage(lieuxQuery.error)}
        </Banner>
      ) : null}

      {lieuxQuery.isSuccess && lieux.length === 0 ? (
        <EmptyState
          compact
          title="Aucun lieu pour l’instant"
          message="Ajoute ta maison, ta salle ou ton parcours dehors, avec le matériel qui s’y trouve."
        />
      ) : null}

      {lieux.length > 0 ? (
        <ul className="space-y-2">
          {lieux.map((lieu) => (
            <li key={lieu.id} className="rounded-2xl border border-slate-200 bg-white px-4 py-3">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-semibold text-slate-900">{lieu.nom}</p>
                    {lieu.par_defaut ? <Pill tone="emerald">Par défaut</Pill> : null}
                  </div>
                  <p className="mt-0.5 text-xs text-slate-500">{lieu.type_libelle ?? lieu.type}</p>
                  <p className="mt-1 text-sm text-slate-600">
                    {resumeMateriel(lieu.materiel, libelle, 6)}
                  </p>
                </div>
              </div>
              <div className="mt-3 flex flex-wrap gap-2">
                <Button size="sm" variant="secondary" onClick={() => setVue({ mode: "edition", lieu })}>
                  Modifier
                </Button>
                {!lieu.par_defaut ? (
                  <Button
                    size="sm"
                    variant="ghost"
                    loading={parDefaut.isPending && parDefaut.variables?.id === lieu.id}
                    disabled={parDefaut.isPending}
                    onClick={() => parDefaut.mutate(lieu)}
                  >
                    Définir par défaut
                  </Button>
                ) : null}
                <Button size="sm" variant="ghost" onClick={() => setASupprimer(lieu)}>
                  Supprimer
                </Button>
              </div>
            </li>
          ))}
        </ul>
      ) : null}

      <div className="flex flex-wrap gap-2">
        <Button onClick={() => setVue({ mode: "edition", lieu: null })} disabled={lieuxQuery.isError}>
          + Ajouter un lieu
        </Button>
        <Button variant="secondary" onClick={onClose}>
          Fermer
        </Button>
      </div>

      <ConfirmDialog
        open={aSupprimer !== null}
        onClose={() => setASupprimer(null)}
        title={aSupprimer ? `Supprimer « ${aSupprimer.nom} » ?` : "Supprimer ce lieu ?"}
        message={
          aSupprimer?.par_defaut
            ? "C’est ton lieu par défaut : le plus ancien des autres le deviendra. Tes séances passées ne changent pas."
            : "Tes séances passées ne changent pas."
        }
        confirmLabel="Supprimer"
        danger
        onConfirm={async () => {
          if (!aSupprimer) return;
          await sportApi.deleteLieu(aSupprimer.id);
          await rafraichir();
          success("Lieu supprimé.");
        }}
      />
    </div>
  );
}

/* ------------------------------------------------------------------ */

function LieuForm({
  lieu,
  premier,
  onCancel,
  onSaved,
}: {
  lieu: LieuEntrainement | null;
  /** Premier lieu du compte : il sera de toute façon le lieu par défaut. */
  premier: boolean;
  onCancel: () => void;
  onSaved: (lieu: LieuEntrainement) => void | Promise<void>;
}) {
  const { vocab } = useSportVocab();
  const { catalogue, presets } = useMaterielCatalogue();

  const [nom, setNom] = useState(lieu?.nom ?? "");
  const [type, setType] = useState<SportLieu>(lieu?.type ?? "maison");
  const [materiel, setMateriel] = useState<Materiel[]>(lieu?.materiel ?? presets.maison);
  // Tant que la personne n’a pas touché au matériel, changer de type applique son préréglage.
  const [materielTouche, setMaterielTouche] = useState(lieu !== null);
  const [defaut, setDefaut] = useState(lieu?.par_defaut ?? premier);
  const [erreurs, setErreurs] = useState<{ nom?: string; materiel?: string }>({});
  const [banner, setBanner] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: (input: LieuEntrainementInput) =>
      lieu ? sportApi.updateLieu(lieu.id, input) : sportApi.createLieu(input),
    onSuccess: async (response) => {
      await onSaved(response.data);
    },
    onError: (error) => {
      if (isApiError(error) && error.isValidation) {
        setErreurs({ nom: error.fieldError("nom"), materiel: error.fieldError("materiel") });
        const reste = error.fieldError("type");
        if (reste) setBanner(reste);
        return;
      }
      setBanner(getErrorMessage(error));
    },
  });

  function choisirType(next: SportLieu) {
    setType(next);
    if (!materielTouche) setMateriel(presets[next] ?? []);
    if (!nom.trim() || nom === libelleType(type)) setNom(libelleType(next));
  }

  function libelleType(valeur: SportLieu): string {
    return vocab.label(vocab.lieux, valeur);
  }

  function basculer(valeur: Materiel) {
    setMaterielTouche(true);
    setMateriel((courant) =>
      courant.includes(valeur) ? courant.filter((m) => m !== valeur) : [...courant, valeur],
    );
  }

  function enregistrer() {
    const propre = nom.trim();
    const next: typeof erreurs = {};
    if (!propre) next.nom = "Donne un nom à ce lieu.";
    else if (propre.length > 60) next.nom = "60 caractères maximum.";
    setErreurs(next);
    if (next.nom) return;
    setBanner(null);
    save.mutate({
      nom: propre,
      type,
      // Le poids du corps reste possible partout : une liste vide le signifie aussi.
      materiel: materiel.length > 0 ? materiel : ["aucun"],
      par_defaut: defaut,
    });
  }

  return (
    <form
      className="space-y-5"
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        enregistrer();
      }}
    >
      <p className="text-sm font-semibold text-slate-900">{lieu ? `Modifier « ${lieu.nom} »` : "Nouveau lieu"}</p>

      {banner ? (
        <Banner tone="error" onClose={() => setBanner(null)}>
          {banner}
        </Banner>
      ) : null}

      <Field
        label="Nom du lieu"
        value={nom}
        maxLength={60}
        required
        placeholder="Ex. : Maison, Basic-Fit Lille, Parc de la Citadelle"
        error={erreurs.nom}
        onChange={(event) => setNom(event.target.value)}
      />

      <ChipGroup legend="Type de lieu" hint={materielTouche ? undefined : "Le matériel habituel de ce type est présélectionné."}>
        {vocab.lieux.map((entry) => (
          <Chip
            key={entry.key}
            role="radio"
            selected={type === entry.key}
            onClick={() => choisirType(entry.key as SportLieu)}
          >
            {entry.label}
          </Chip>
        ))}
      </ChipGroup>

      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-sm font-medium text-slate-700">Matériel disponible</p>
          <Button
            size="sm"
            variant="ghost"
            onClick={() => {
              setMateriel(presets[type] ?? []);
              setMaterielTouche(false);
            }}
          >
            Matériel habituel ({libelleType(type).toLowerCase()})
          </Button>
        </div>
        {MATERIEL_CATEGORIE_ORDER.map((categorie) => {
          const items = catalogue.filter((entry) => entry.categorie === categorie);
          if (items.length === 0) return null;
          return (
            <ChipGroup key={categorie} legend={MATERIEL_CATEGORIE_LABELS[categorie]} multiple>
              {items.map((entry) => (
                <Chip
                  key={entry.valeur}
                  selected={materiel.includes(entry.valeur)}
                  onClick={() => basculer(entry.valeur)}
                >
                  {entry.libelle}
                </Chip>
              ))}
            </ChipGroup>
          );
        })}
        {erreurs.materiel ? (
          <p className="text-xs font-medium text-rose-600" role="alert">
            {erreurs.materiel}
          </p>
        ) : null}
      </div>

      <Switch
        checked={defaut}
        onChange={setDefaut}
        disabled={lieu?.par_defaut === true}
        label="Lieu par défaut"
        hint={
          lieu?.par_defaut
            ? "C’est déjà ton lieu par défaut : choisis-en un autre pour le changer."
            : "Présélectionné quand tu génères une séance."
        }
      />

      <div className="flex flex-wrap gap-2">
        <Button type="submit" loading={save.isPending}>
          {lieu ? "Enregistrer" : "Ajouter ce lieu"}
        </Button>
        <Button variant="secondary" onClick={onCancel} disabled={save.isPending}>
          Annuler
        </Button>
      </div>
    </form>
  );
}

export default LieuxModal;
