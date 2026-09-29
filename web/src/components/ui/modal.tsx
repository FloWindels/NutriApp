"use client";

import { useEffect, useId, useRef, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { cn } from "@/lib/cn";

export type ModalSize = "sm" | "md" | "lg" | "xl";

export type ModalProps = {
  open: boolean;
  onClose: () => void;
  title?: ReactNode;
  description?: ReactNode;
  children?: ReactNode;
  /** Sticky footer (actions). */
  footer?: ReactNode;
  size?: ModalSize;
  /** Prevent closing on backdrop click / Escape (e.g. during a mutation). */
  locked?: boolean;
  /** Remove body padding for scrollable lists. */
  bodyClassName?: string;
  className?: string;
};

const sizeClasses: Record<ModalSize, string> = {
  sm: "max-w-md",
  md: "max-w-lg",
  lg: "max-w-2xl",
  xl: "max-w-4xl",
};

const SELECTEUR_FOCUSABLE =
  'input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled]), button:not([disabled]), [href], [tabindex]:not([tabindex="-1"])';

/**
 * Les champs où l’on tape. Le focus d’ouverture ne vise qu’eux : poser le focus sur un bouton
 * ferait de la barre d’espace un clic — c’est ainsi que la croix « Fermer » refermait la boîte
 * dès qu’on tentait d’écrire. Un composant qui veut un autre point de départ pose `data-autofocus`.
 */
const SELECTEUR_SAISIE =
  'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="file"]):not([type="image"]):not([disabled]), textarea:not([disabled]), select:not([disabled])';

function premierVisible(racine: HTMLElement | null, selecteur: string): HTMLElement | null {
  if (!racine) return null;
  for (const element of racine.querySelectorAll<HTMLElement>(selecteur)) {
    if (element.getClientRects().length > 0) return element;
  }
  return null;
}

function focusables(racine: HTMLElement): HTMLElement[] {
  return Array.from(racine.querySelectorAll<HTMLElement>(SELECTEUR_FOCUSABLE)).filter(
    (element) => element.tabIndex >= 0 && element.getClientRects().length > 0,
  );
}

/**
 * Deux boîtes peuvent être ouvertes l’une sur l’autre (une recette et son « ajouter à un repas »).
 * Seule celle du dessus écoute le clavier : sinon Échap les fermerait toutes d’un coup, et le
 * piège à focus de celle du dessous ramènerait la tabulation derrière la boîte active.
 */
const pile: HTMLElement[] = [];

export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = "md",
  locked = false,
  bodyClassName,
  className,
}: ModalProps) {
  const titleId = useId();
  const descriptionId = useId();
  const panelRef = useRef<HTMLDivElement>(null);
  const corpsRef = useRef<HTMLDivElement>(null);
  const previouslyFocused = useRef<Element | null>(null);

  /**
   * `onClose` est souvent une fonction écrite dans le rendu du parent : son identité change à
   * chaque frappe. Si l’effet en dépendait, il se rejouerait à chaque caractère tapé et
   * redonnerait le focus au panneau, volant le curseur du champ en cours de saisie.
   */
  const lockedRef = useRef(locked);
  const onCloseRef = useRef(onClose);
  useEffect(() => {
    lockedRef.current = locked;
    onCloseRef.current = onClose;
  });

  useEffect(() => {
    if (!open) return;
    const panel = panelRef.current;
    if (!panel) return;

    previouslyFocused.current = document.activeElement;
    const { overflow } = document.body.style;
    document.body.style.overflow = "hidden";
    pile.push(panel);

    const depart =
      premierVisible(panel, "[data-autofocus]:not([disabled])") ??
      premierVisible(corpsRef.current, SELECTEUR_SAISIE) ??
      panel;
    depart.focus({ preventScroll: true });

    function onKeyDown(event: KeyboardEvent) {
      if (pile[pile.length - 1] !== panel) return;

      if (event.key === "Escape" && !lockedRef.current) {
        event.stopPropagation();
        onCloseRef.current();
        return;
      }
      if (event.key !== "Tab") return;

      const cibles = focusables(panel);
      if (cibles.length === 0) {
        event.preventDefault();
        panel.focus({ preventScroll: true });
        return;
      }
      const premier = cibles[0];
      const dernier = cibles[cibles.length - 1];
      const actif = document.activeElement;
      if (!(actif instanceof HTMLElement) || !panel.contains(actif)) {
        event.preventDefault();
        (event.shiftKey ? dernier : premier).focus();
      } else if (event.shiftKey && actif === premier) {
        event.preventDefault();
        dernier.focus();
      } else if (!event.shiftKey && actif === dernier) {
        event.preventDefault();
        premier.focus();
      }
    }

    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("keydown", onKeyDown);
      document.body.style.overflow = overflow;
      const rang = pile.lastIndexOf(panel);
      if (rang !== -1) pile.splice(rang, 1);
      const previous = previouslyFocused.current;
      if (previous instanceof HTMLElement) previous.focus({ preventScroll: true });
    };
  }, [open]);

  if (!open || typeof document === "undefined") return null;

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4">
      <div
        className="absolute inset-0 bg-slate-950/40 backdrop-blur-[2px]"
        onClick={locked ? undefined : onClose}
        aria-hidden="true"
      />
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? titleId : undefined}
        aria-describedby={description ? descriptionId : undefined}
        tabIndex={-1}
        className={cn(
          "relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-[1.75rem] border border-slate-200 bg-white shadow-[0_30px_80px_rgba(15,23,42,0.25)] outline-none sm:rounded-[1.75rem]",
          sizeClasses[size],
          className,
        )}
      >
        {title || description ? (
          <header className="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4 sm:px-6">
            <div className="min-w-0">
              {title ? (
                <h2 id={titleId} className="text-lg font-semibold tracking-tight text-slate-950">
                  {title}
                </h2>
              ) : null}
              {description ? (
                <p id={descriptionId} className="mt-0.5 text-sm text-slate-500">
                  {description}
                </p>
              ) : null}
            </div>
            <button
              type="button"
              onClick={onClose}
              disabled={locked}
              aria-label="Fermer"
              className="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-50"
            >
              <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
                <path d="M5 5L15 15M15 5L5 15" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
              </svg>
            </button>
          </header>
        ) : null}
        <div ref={corpsRef} className={cn("min-h-0 flex-1 overflow-y-auto px-5 py-4 sm:px-6", bodyClassName)}>
          {children}
        </div>
        {footer ? (
          <footer className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 bg-slate-50/60 px-5 py-3 sm:px-6">
            {footer}
          </footer>
        ) : null}
      </div>
    </div>,
    document.body,
  );
}

export default Modal;
