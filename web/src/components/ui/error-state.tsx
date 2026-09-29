"use client";

import type { ReactNode } from "react";
import { getErrorMessage } from "@/lib/api-client";
import { cn } from "@/lib/cn";
import { messages } from "@/lib/messages";
import { capaciteRequise, estOffreRequise } from "@/lib/offres-capacites";
import { Button } from "./button";
import { OffreRequisePourCapacite } from "./offre-requise";

export type ErrorStateProps = {
  /**
   * L'erreur telle qu'elle a été levée, pas seulement son message.
   *
   * Elle est obligatoire parce que c'est elle qui tranche entre une panne et une porte
   * fermée : un 402 n'est pas un incident, c'est une fonctionnalité qui existe et qu'il faut
   * débloquer. En exigeant l'objet, aucun écran ne peut prendre cette décision à sa place ni
   * l'oublier. Passe `null` quand il n'y a pas d'erreur à montrer (une ressource absente,
   * par exemple) et donne alors `message` toi-même.
   */
  error: unknown;
  title?: ReactNode;
  /** Remplace le message tiré de `error`. */
  message?: ReactNode;
  onRetry?: () => void;
  retryLabel?: string;
  retrying?: boolean;
  compact?: boolean;
  className?: string;
};

export function ErrorState({
  error,
  title = "Oups, quelque chose s’est mal passé.",
  message,
  onRetry,
  retryLabel = messages.retry,
  retrying = false,
  compact = false,
  className,
}: ErrorStateProps) {
  // Une porte fermée n'est pas une panne : ni bordure rose, ni triangle, ni bouton « Réessayer »,
  // qui ne ferait que rejouer le même refus.
  if (estOffreRequise(error)) {
    return (
      <OffreRequisePourCapacite
        capacite={capaciteRequise(error)}
        compact={compact}
        className={className}
      />
    );
  }

  const texte = message ?? getErrorMessage(error);

  return (
    <div
      role="alert"
      className={cn(
        "flex flex-col items-center justify-center rounded-2xl border border-rose-200 bg-rose-50/70 text-center",
        compact ? "px-4 py-6" : "px-6 py-12",
        className,
      )}
    >
      <div className="mb-3 grid h-12 w-12 place-items-center rounded-2xl bg-white text-rose-600 shadow-sm">
        <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" aria-hidden="true">
          <path d="M12 8V13M12 16.5V16.6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
          <path d="M10.3 3.9L2.6 17.5C1.9 18.8 2.8 20.5 4.3 20.5H19.7C21.2 20.5 22.1 18.8 21.4 17.5L13.7 3.9C12.9 2.6 11.1 2.6 10.3 3.9Z" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" />
        </svg>
      </div>
      <p className="text-base font-semibold text-rose-900">{title}</p>
      {texte ? <p className="mt-1 max-w-md text-sm leading-6 text-rose-800/80">{texte}</p> : null}
      {onRetry ? (
        <Button variant="secondary" className="mt-4" onClick={onRetry} loading={retrying}>
          {retryLabel}
        </Button>
      ) : null}
    </div>
  );
}

export default ErrorState;
