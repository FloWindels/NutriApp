"use client";

import { useMemo, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { MagasinChoix } from "@/components/shopping/magasin-choix";
import { PanierTotal } from "@/components/shopping/panier-total";
import { PromotionsCard } from "@/components/shopping/promotions-card";
import { ShoppingRow } from "@/components/shopping/shopping-row";
import { ToStockModal } from "@/components/shopping/to-stock-modal";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { EmptyState } from "@/components/ui/empty-state";
import { ErrorState } from "@/components/ui/error-state";
import { Field, SelectField } from "@/components/ui/field";
import { ModulePayant } from "@/components/ui/offre-requise";
import { SectionHeader, Overline } from "@/components/ui/section-header";
import { SkeletonList } from "@/components/ui/skeleton";
import { useToast } from "@/components/ui/toast";
import { apiDelete, apiGet, apiPost, apiPut, getErrorMessage } from "@/lib/api-client";
import { parseDecimal, startOfWeekMonday, todayIso } from "@/lib/format";
import { queryKeys } from "@/lib/query-keys";
import { useMagasins } from "@/hooks/use-magasins";
import { usePortions } from "@/hooks/use-portions";
import { unitLabel } from "@/lib/units";
import type {
  DataEnvelope,
  Household,
  MessageEnvelope,
  ShoppingGenerateResponse,
  ShoppingItemLigne,
  ShoppingListResponse,
  ShoppingTri,
  UserSettings,
} from "@/lib/types/api";

/** « Liste de courses » — manuelle, générée depuis le planning, partagée avec le foyer (§11). */
export default function ShoppingListRoute() {
  return (
    <ModulePayant capacite="courses">
      <ShoppingListPage />
    </ModulePayant>
  );
}

/** Un rayon du magasin et ce qu'on y prend, dans l'ordre où on le traverse. */
type GroupeRayon = { cle: string; libelle: string; lignes: ShoppingItemLigne[] };

function ShoppingListPage() {
  const queryClient = useQueryClient();
  const { success, error: toastError } = useToast();
  const { portions } = usePortions();
  const { magasins } = useMagasins();

  const [label, setLabel] = useState("");
  const [quantity, setQuantity] = useState("");
  const [unit, setUnit] = useState("");
  const [formError, setFormError] = useState<string | null>(null);
  const [toStock, setToStock] = useState<ShoppingItemLigne | null>(null);
  const [clearOpen, setClearOpen] = useState(false);
  const [triChoisi, setTriChoisi] = useState<ShoppingTri>("rayon");

  // Le magasin regardé est le magasin préféré : le choisir ici, c'est le choisir pour de bon.
  // Deux réglages qui se contredisent d'un écran à l'autre valent moins que pas de réglage.
  const settingsQuery = useQuery({
    queryKey: queryKeys.settings,
    queryFn: () => apiGet<DataEnvelope<UserSettings>>("/settings"),
  });

  const magasinId = settingsQuery.data?.data.magasin_prefere_id ?? null;
  const tri: ShoppingTri = magasinId === null ? "ajout" : triChoisi;

  const listQuery = useQuery({
    queryKey: queryKeys.shopping.list({ magasin_id: magasinId, tri }),
    queryFn: () => apiGet<ShoppingListResponse>("/shopping-list", { magasin_id: magasinId, tri }),
    // Charger la liste avant de savoir dans quel magasin on est la ferait clignoter : elle
    // arriverait sans prix, puis se réafficherait avec. On attend la réponse des réglages —
    // ou son échec, qui laisse simplement la liste sans magasin.
    enabled: !settingsQuery.isPending,
  });

  const householdQuery = useQuery({
    queryKey: queryKeys.household.current,
    queryFn: () => apiGet<DataEnvelope<Household | null>>("/household"),
  });

  const household = householdQuery.data?.data ?? null;
  const items = useMemo(() => listQuery.data?.data ?? [], [listQuery.data]);
  const counts = listQuery.data?.counts ?? { total: 0, checked: 0 };
  const magasin = listQuery.data?.magasin ?? null;
  const estimation = listQuery.data?.estimation ?? null;

  const { pending, checked } = useMemo(
    () => ({
      pending: items.filter((item) => !item.checked),
      checked: items.filter((item) => item.checked),
    }),
    [items],
  );

  /**
   * Le serveur rend déjà les lignes dans l'ordre de traversée du magasin ; il suffit donc de
   * couper à chaque changement de rayon. Regrouper soi-même par la liste `rayons` perdrait les
   * articles rattachés à un produit dont le prix est inconnu, qui n'y figurent pas.
   */
  const groupes = useMemo<GroupeRayon[]>(() => {
    if (listQuery.data?.tri !== "rayon") return [];

    const resultat: GroupeRayon[] = [];

    for (const item of items) {
      const cle = item.magasin_produit?.rayon ?? "sans_correspondance";
      const libelle = item.magasin_produit?.rayon_libelle ?? "Sans équivalent dans ce magasin";
      const dernier = resultat.at(-1);

      if (dernier && dernier.cle === cle) dernier.lignes.push(item);
      else resultat.push({ cle, libelle, lignes: [item] });
    }

    return resultat;
  }, [items, listQuery.data]);

  async function refresh() {
    await queryClient.invalidateQueries({ queryKey: queryKeys.shopping.all });
  }

  /** Écriture optimiste, comme dans les paramètres : le magasin change sous les doigts. */
  const choisirMagasin = useMutation({
    mutationFn: (id: number | null) =>
      apiPut<DataEnvelope<UserSettings>>("/settings", { magasin_prefere_id: id }),
    onMutate: async (id) => {
      await queryClient.cancelQueries({ queryKey: queryKeys.settings });
      const previous = queryClient.getQueryData<DataEnvelope<UserSettings>>(queryKeys.settings);
      if (previous) {
        queryClient.setQueryData<DataEnvelope<UserSettings>>(queryKeys.settings, {
          ...previous,
          data: { ...previous.data, magasin_prefere_id: id },
        });
      }
      return { previous };
    },
    onError: (error, _id, context) => {
      if (context?.previous) queryClient.setQueryData(queryKeys.settings, context.previous);
      toastError(getErrorMessage(error));
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: queryKeys.settings }),
  });

  const add = useMutation({
    mutationFn: () =>
      apiPost<DataEnvelope<ShoppingItemLigne>>("/shopping-list/items", {
        label: label.trim(),
        quantity: quantity.trim() ? parseDecimal(quantity) : null,
        unit: unit || null,
      }),
    onSuccess: async () => {
      setLabel("");
      setQuantity("");
      setUnit("");
      setFormError(null);
      await refresh();
    },
    onError: (error) => setFormError(getErrorMessage(error)),
  });

  const toggle = useMutation({
    mutationFn: (input: { item: ShoppingItemLigne; checked: boolean }) =>
      apiPut<DataEnvelope<ShoppingItemLigne>>(`/shopping-list/items/${input.item.id}`, {
        checked: input.checked,
      }),
    onSuccess: refresh,
    onError: (error) => toastError(getErrorMessage(error)),
  });

  const remove = useMutation({
    mutationFn: (item: ShoppingItemLigne) =>
      apiDelete<MessageEnvelope>(`/shopping-list/items/${item.id}`),
    onSuccess: async () => {
      await refresh();
      success("Article retiré de la liste.");
    },
    onError: (error) => toastError(getErrorMessage(error)),
  });

  const clearChecked = useMutation({
    mutationFn: () => apiDelete<MessageEnvelope>("/shopping-list/checked"),
    onSuccess: async () => {
      setClearOpen(false);
      await refresh();
      success("Articles cochés effacés.");
    },
    onError: (error) => toastError(getErrorMessage(error)),
  });

  const generate = useMutation({
    mutationFn: () =>
      apiPost<ShoppingGenerateResponse>("/shopping-list/generate", {
        week_start: startOfWeekMonday(todayIso()),
        magasin_id: magasinId,
        tri,
      }),
    onSuccess: async (response) => {
      await refresh();
      success(
        response.added_count > 0
          ? `${response.added_count} article${response.added_count > 1 ? "s" : ""} ajouté${response.added_count > 1 ? "s" : ""} depuis le planning.`
          : "Rien à ajouter : tout est déjà dans ta liste ou dans ton stock.",
      );
    },
    onError: (error) => toastError(getErrorMessage(error)),
  });

  return (
    <div className="space-y-6">
      <SectionHeader
        eyebrow={household ? `Liste du foyer · ${household.name}` : "Liste de courses"}
        title="Liste de courses"
        subtitle={
          counts.total > 0
            ? `${counts.total - counts.checked} à acheter · ${counts.checked} coché${counts.checked > 1 ? "s" : ""}`
            : "Ajoute ce qu’il te manque, ou génère la liste depuis ton planning."
        }
        tone="slate"
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => generate.mutate()} loading={generate.isPending}>
              Générer depuis le planning
            </Button>
            {counts.checked > 0 ? (
              <Button variant="ghost" onClick={() => setClearOpen(true)}>
                Effacer les cochés
              </Button>
            ) : null}
          </div>
        }
      />

      <MagasinChoix
        magasins={magasins}
        magasinId={magasinId}
        onMagasin={(id) => choisirMagasin.mutate(id)}
        tri={tri}
        onTri={setTriChoisi}
        enCours={choisirMagasin.isPending || settingsQuery.isPending}
      />

      <Card padding="md">
        <form
          className="grid gap-3 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end"
          onSubmit={(event) => {
            event.preventDefault();
            if (!label.trim()) {
              setFormError("Indique ce que tu veux acheter.");
              return;
            }
            add.mutate();
          }}
        >
          <Field
            label="Article"
            placeholder="Ex. : lait demi-écrémé"
            value={label}
            onChange={(event) => setLabel(event.target.value)}
          />
          <Field
            label="Quantité"
            inputMode="decimal"
            className="sm:w-28"
            value={quantity}
            onChange={(event) => setQuantity(event.target.value)}
          />
          <SelectField
            label="Unité"
            className="sm:w-40"
            value={unit}
            onChange={(event) => setUnit(event.target.value)}
          >
            <option value="">—</option>
            {portions.map((portion) => (
              <option key={portion.unit} value={portion.unit}>
                {unitLabel(portion.unit, portions, false)}
              </option>
            ))}
          </SelectField>
          <Button type="submit" loading={add.isPending}>
            Ajouter
          </Button>
        </form>
        {formError ? (
          <Banner tone="error" className="mt-3" onClose={() => setFormError(null)}>
            {formError}
          </Banner>
        ) : null}
      </Card>

      {magasin ? <PromotionsCard magasin={magasin} /> : null}

      {listQuery.isPending ? <SkeletonList /> : null}

      {listQuery.isError ? (
        <ErrorState error={listQuery.error} onRetry={() => listQuery.refetch()} />
      ) : null}

      {listQuery.isSuccess && items.length === 0 ? (
        <EmptyState
          title="Ta liste est vide"
          message="Ajoute un article ci-dessus, ou génère la liste à partir de ton planning de la semaine."
          action={
            <Button onClick={() => generate.mutate()} loading={generate.isPending}>
              Générer depuis le planning
            </Button>
          }
        />
      ) : null}

      {groupes.length > 0
        ? groupes.map((groupe) => (
            <Card key={groupe.cle} padding="md">
              <Overline className="mb-2">{groupe.libelle}</Overline>
              <ul className="space-y-2">
                {groupe.lignes.map((item) => (
                  <ShoppingRow
                    key={item.id}
                    item={item}
                    avecMagasin={magasin !== null}
                    onToggle={(target, value) => toggle.mutate({ item: target, checked: value })}
                    onToStock={setToStock}
                    onDelete={(target) => remove.mutate(target)}
                  />
                ))}
              </ul>
            </Card>
          ))
        : null}

      {groupes.length === 0 && pending.length > 0 ? (
        <Card padding="md">
          <Overline className="mb-2">
            À acheter
          </Overline>
          <ul className="space-y-2">
            {pending.map((item) => (
              <ShoppingRow
                key={item.id}
                item={item}
                avecMagasin={magasin !== null}
                onToggle={(target, value) => toggle.mutate({ item: target, checked: value })}
                onToStock={setToStock}
                onDelete={(target) => remove.mutate(target)}
              />
            ))}
          </ul>
        </Card>
      ) : null}

      {groupes.length === 0 && checked.length > 0 ? (
        <Card padding="md">
          <Overline className="mb-2">
            Déjà pris
          </Overline>
          <ul className="space-y-2">
            {checked.map((item) => (
              <ShoppingRow
                key={item.id}
                item={item}
                avecMagasin={magasin !== null}
                onToggle={(target, value) => toggle.mutate({ item: target, checked: value })}
                onToStock={setToStock}
                onDelete={(target) => remove.mutate(target)}
              />
            ))}
          </ul>
        </Card>
      ) : null}

      {estimation && items.length > 0 ? (
        <PanierTotal estimation={estimation} magasin={magasin} />
      ) : null}

      <ToStockModal
        open={toStock !== null}
        onClose={() => setToStock(null)}
        item={toStock}
        onStored={async () => {
          setToStock(null);
          await refresh();
          await queryClient.invalidateQueries({ queryKey: queryKeys.stocks.all });
          success("Article ajouté à ton stock.");
        }}
      />

      <ConfirmDialog
        open={clearOpen}
        onClose={() => setClearOpen(false)}
        title="Effacer les articles cochés ?"
        message="Ils disparaîtront de la liste. Les articles non cochés sont conservés."
        confirmLabel="Effacer"
        onConfirm={async () => {
          await clearChecked.mutateAsync();
        }}
      />
    </div>
  );
}
