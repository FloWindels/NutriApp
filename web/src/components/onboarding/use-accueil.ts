"use client";

import { useSyncExternalStore } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useMe } from "@/hooks/use-me";
import { apiGet, apiPut } from "@/lib/api-client";
import { queryKeys } from "@/lib/query-keys";
import type { DataEnvelope, SettingsInput, UserSettings } from "@/lib/types/api";

/**
 * L'accueil d'un nouveau compte : le questionnaire de première connexion, puis la visite guidée.
 *
 * Ce qui est fait se lit dans les paramètres du serveur (`onboarding_termine_le`,
 * `visite_vue_le`) : l'accueil suit la personne d'un appareil à l'autre. Seul « Plus tard » reste
 * local, et pour la durée de l'onglet : on reposera la question à la prochaine visite.
 */

const CLE_REPORTE = "mavioh:accueil-reporte";

// Petit magasin hors React : la demande de visite traverse une navigation (Paramètres →
// Accueil), et le cadre du tableau de bord, lui, reste monté.
let reporte: boolean | null = null;
let visiteDemandee = false;
let visiteEnCours = false;
const abonnes = new Set<() => void>();

function prevenir() {
  for (const abonne of abonnes) abonne();
}

function abonner(abonne: () => void) {
  abonnes.add(abonne);
  return () => {
    abonnes.delete(abonne);
  };
}

function lireReporte(): boolean {
  if (reporte !== null) return reporte;
  try {
    reporte = window.sessionStorage.getItem(CLE_REPORTE) === "1";
  } catch {
    reporte = false;
  }
  return reporte;
}

/** « Plus tard » : ne plus rediriger vers le questionnaire pendant cette session. */
export function reporterAccueil() {
  reporte = true;
  try {
    window.sessionStorage.setItem(CLE_REPORTE, "1");
  } catch {
    // Navigation privée : le report tient tout de même jusqu'au rechargement.
  }
  prevenir();
}

export function useAccueilReporte(): boolean {
  return useSyncExternalStore(abonner, lireReporte, () => false);
}

/** Relance la visite guidée à la prochaine arrivée sur l'accueil (bouton des Paramètres). */
export function demanderVisite() {
  visiteDemandee = true;
  prevenir();
}

export function oublierDemandeVisite() {
  if (!visiteDemandee) return;
  visiteDemandee = false;
  prevenir();
}

/**
 * La visite guidée est ouverte : elle navigue de page en page, le tiroir du téléphone reste
 * fermé et la garde de l'accueil ne redirige pas vers le questionnaire en plein parcours.
 */
export function signalerVisiteEnCours(enCours: boolean) {
  if (visiteEnCours === enCours) return;
  visiteEnCours = enCours;
  prevenir();
}

export function useVisiteEnCours(): boolean {
  return useSyncExternalStore(
    abonner,
    () => visiteEnCours,
    () => false,
  );
}

export function useVisiteDemandee(): boolean {
  return useSyncExternalStore(
    abonner,
    () => visiteDemandee,
    () => false,
  );
}

/**
 * Paramètres (même clé de cache que la page Paramètres) et un enregistrement des deux étapes
 * de l'accueil qui met le cache à jour sans attendre un nouvel aller-retour.
 */
export function useParametresAccueil() {
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: queryKeys.settings,
    queryFn: () => apiGet<DataEnvelope<UserSettings>>("/settings"),
    staleTime: 60 * 1000,
  });

  const marquer = useMutation({
    mutationFn: (patch: Pick<SettingsInput, "onboarding_termine" | "visite_vue">) =>
      apiPut<DataEnvelope<UserSettings>>("/settings", patch),
    onSuccess: (reponse) => queryClient.setQueryData(queryKeys.settings, reponse),
  });

  return { settings: query.data?.data ?? null, marquer };
}

/**
 * Faut-il proposer le questionnaire ? Seulement quand on sait : un profil incomplet d'après
 * `/me`, et un questionnaire jamais terminé d'après les paramètres. Dans le doute, on ne
 * redirige personne.
 */
export function useQuestionnaireAttendu(): boolean {
  const me = useMe();
  const { settings } = useParametresAccueil();
  const reporteCetteSession = useAccueilReporte();

  if (!me.data || !settings || reporteCetteSession) return false;
  return !me.data.has_profile && settings.onboarding_termine_le === null;
}
