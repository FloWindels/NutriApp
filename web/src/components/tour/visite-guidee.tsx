"use client";

import { usePathname, useRouter } from "next/navigation";
import { useCallback, useEffect, useId, useRef, useState, type CSSProperties } from "react";
import { Button } from "@/components/ui/button";
import { useMe } from "@/hooks/use-me";
import { useOffre } from "@/hooks/use-offre";
import { cn } from "@/lib/cn";
import { argumentaire } from "@/lib/offres-capacites";
import {
  oublierDemandeVisite,
  signalerVisiteEnCours,
  useParametresAccueil,
  useQuestionnaireAttendu,
  useVisiteDemandee,
} from "@/components/onboarding/use-accueil";
import { ACCUEIL, ETAPES_VISITE, type EtapeVisite } from "./etapes";

/**
 * Visite guidée de Mavi'oh, montée une fois par le cadre du tableau de bord.
 *
 * Elle s'ouvre d'elle-même sur l'accueil tant que le serveur dit qu'elle n'a jamais été vue
 * (`visite_vue_le`), sauf si le questionnaire de bienvenue doit passer avant ; ou à la demande,
 * depuis les Paramètres. La terminer comme la passer la marque vue, sur tous les appareils.
 *
 * Une fois lancée, elle emmène de page en page : le cadre du tableau de bord restant monté
 * d'une page à l'autre, son état survit à chaque navigation. Elle se referme sur l'accueil.
 */
export function VisiteGuidee() {
  const pathname = usePathname();
  const router = useRouter();
  const me = useMe();
  const { settings, marquer } = useParametresAccueil();
  const questionnaireAttendu = useQuestionnaireAttendu();
  const demandee = useVisiteDemandee();
  // Fermée pendant cette session : ne pas la rouvrir avant que le serveur ait répondu.
  const [fermee, setFermee] = useState(false);
  const [lancee, setLancee] = useState(false);

  const surAccueil = pathname === ACCUEIL;
  const auto =
    !fermee &&
    me.data !== undefined &&
    settings !== null &&
    settings.visite_vue_le === null &&
    !questionnaireAttendu;

  // Elle ne démarre que sur l'accueil ; ensuite, c'est elle qui choisit la page.
  if (!lancee && surAccueil && (demandee || auto)) setLancee(true);
  if (!lancee) return null;

  function terminer() {
    setLancee(false);
    setFermee(true);
    oublierDemandeVisite();
    if (settings?.visite_vue_le == null) marquer.mutate({ visite_vue: true });
    if (pathname !== ACCUEIL) router.push(ACCUEIL);
  }

  return <Visite onTerminer={terminer} />;
}

type Zone = { left: number; top: number; right: number; bottom: number };

type Cadre = {
  /** Le repère éclairé, tel qu'il est à l'écran ; null pendant la recherche ou sans repère. */
  rect: Zone | null;
  /** La carte d'explication, pour que le trou du voile ne passe jamais dessous. */
  carte: Zone | null;
  /** La recherche du repère est finie (trouvé, ou abandonnée au profit d'une carte centrée). */
  decide: boolean;
  vw: number;
  vh: number;
};

/** Repères communs à toutes les pages : la carte d'une page verrouillée, puis l'en-tête. */
const OFFRE_REQUISE = "offre-requise";
const REPLIS_COMMUNS = [OFFRE_REQUISE, "entete-page"];

/** Après ce délai sans repère, on se rabat sur un repli : la visite ne reste jamais bloquée. */
const DELAI_CIBLE = 2500;
/** Une page verrouillée l'est d'emblée : inutile d'attendre aussi longtemps. */
const DELAI_OFFRE = 300;
/** Si la navigation n'aboutit pas, on cherche tout de même dans la page affichée. */
const DELAI_ROUTE = 4000;

/** Largeur à partir de laquelle la barre latérale de l'ordinateur remplace les onglets du bas. */
const LARGEUR_ORDINATEUR = 1024;

const MARGE = 16;
const ECART = 12;
const BORD = 6;

function estVisible(element: HTMLElement): boolean {
  const rect = element.getBoundingClientRect();
  return element.getClientRects().length > 0 && rect.width > 0 && rect.height > 0;
}

/** Premier repère visible parmi ceux donnés, dans l'ordre de préférence. */
function trouverCible(cibles: string[]): HTMLElement | null {
  for (const cible of cibles) {
    const elements = document.querySelectorAll<HTMLElement>(`[data-tour="${cible}"]`);
    for (const element of elements) {
      if (estVisible(element)) return element;
    }
  }
  return null;
}

function tousLesReperes(etape: EtapeVisite): string[] {
  return [...etape.cibles, ...(etape.replis ?? []), ...(etape.sansRepli ? [] : REPLIS_COMMUNS)];
}

/**
 * Amène le repère en haut de l'écran, sous la barre du téléphone : la carte se range alors en
 * bas, sans le recouvrir.
 */
function amener(element: HTMLElement) {
  const telephone = window.innerWidth < LARGEUR_ORDINATEUR;
  const barre = telephone
    ? (document.querySelector<HTMLElement>('[data-tour="barre-mobile"]')?.getBoundingClientRect().bottom ?? 0)
    : 0;
  const avant = element.style.scrollMarginTop;
  element.style.scrollMarginTop = `${Math.max(0, barre) + MARGE}px`;
  element.scrollIntoView({ block: "start", inline: "nearest", behavior: "instant" });
  element.style.scrollMarginTop = avant;
}

function zone(rect: DOMRect): Zone {
  return { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom };
}

function Visite({ onTerminer }: { onTerminer: () => void }) {
  const router = useRouter();
  const pathname = usePathname();
  const [index, setIndex] = useState(0);
  const [cadre, setCadre] = useState<Cadre | null>(null);
  const carteRef = useRef<HTMLDivElement>(null);
  const titreRef = useRef<HTMLHeadingElement>(null);
  const cheminRef = useRef(pathname);
  const titreId = useId();
  const texteId = useId();
  const { peut, pret } = useOffre();

  const etape = ETAPES_VISITE[index];
  const derniere = index === ETAPES_VISITE.length - 1;

  const suivant = useCallback(() => {
    if (index >= ETAPES_VISITE.length - 1) onTerminer();
    else setIndex(index + 1);
  }, [index, onTerminer]);
  const precedent = useCallback(() => setIndex((i) => Math.max(i - 1, 0)), []);

  useEffect(() => {
    cheminRef.current = pathname;
  }, [pathname]);

  // Le tiroir se ferme et la garde de l'accueil se tait tant que la visite dure.
  useEffect(() => {
    signalerVisiteEnCours(true);
    return () => signalerVisiteEnCours(false);
  }, []);

  // Chaque étape ouvre la vraie page de la fonctionnalité.
  useEffect(() => {
    if (cheminRef.current !== etape.route) router.push(etape.route);
  }, [etape, router]);

  // Attente du repère une fois la page affichée, puis suivi image par image : la page charge
  // ses données, défile, change de taille… le trou du voile la suit.
  useEffect(() => {
    const debutEtape = performance.now();
    let debutRecherche: number | null = null;
    let element: HTMLElement | null = null;
    let decide = etape.cibles.length === 0;
    let derniereCle = "";
    let image = 0;

    const suivre = () => {
      const maintenant = performance.now();

      if (!decide) {
        if (
          debutRecherche === null &&
          (cheminRef.current === etape.route || maintenant - debutEtape > DELAI_ROUTE)
        ) {
          debutRecherche = maintenant;
        }
        if (debutRecherche !== null) {
          const ecoule = maintenant - debutRecherche;
          element =
            trouverCible(etape.cibles) ??
            (!etape.sansRepli && ecoule > DELAI_OFFRE ? trouverCible([OFFRE_REQUISE]) : null) ??
            (ecoule > DELAI_CIBLE ? trouverCible(tousLesReperes(etape)) : null);
          if (element || ecoule > DELAI_CIBLE) {
            decide = true;
            if (element) amener(element);
          }
        }
      } else if (element && !element.isConnected) {
        // La page a remplacé l'élément en se rechargeant : on retrouve son successeur.
        element = trouverCible(tousLesReperes(etape));
      }

      const rect = element && estVisible(element) ? zone(element.getBoundingClientRect()) : null;
      const carteRect = carteRef.current?.getBoundingClientRect();
      const carte = carteRect ? zone(carteRect) : null;
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      const cle = [rect?.left, rect?.top, rect?.right, rect?.bottom, carte?.top, carte?.bottom, vw, vh]
        .map((valeur) => (valeur === undefined ? "-" : String(Math.round(valeur))))
        .concat(decide ? "1" : "0")
        .join("|");
      if (cle !== derniereCle) {
        derniereCle = cle;
        setCadre({ rect, carte, decide, vw, vh });
      }
      image = window.requestAnimationFrame(suivre);
    };

    image = window.requestAnimationFrame(suivre);
    return () => window.cancelAnimationFrame(image);
  }, [etape]);

  // Le focus suit l'étape, et revient où il était à la fermeture.
  useEffect(() => {
    titreRef.current?.focus({ preventScroll: true });
  }, [index]);
  useEffect(() => {
    const avant = document.activeElement as HTMLElement | null;
    return () => avant?.focus?.({ preventScroll: true });
  }, []);

  // Clavier : Échap passe, les flèches avancent ou reculent, Tab reste dans la carte.
  useEffect(() => {
    const surTouche = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        onTerminer();
      } else if (event.key === "ArrowRight") {
        event.preventDefault();
        suivant();
      } else if (event.key === "ArrowLeft") {
        event.preventDefault();
        precedent();
      } else if (event.key === "Tab" && carteRef.current) {
        const focusables = carteRef.current.querySelectorAll<HTMLElement>(
          'button:not([disabled]), [href], [tabindex]:not([tabindex="-1"])',
        );
        if (focusables.length === 0) return;
        const premier = focusables[0];
        const dernier = focusables[focusables.length - 1];
        const actif = document.activeElement;
        if (event.shiftKey && (actif === premier || !carteRef.current.contains(actif))) {
          event.preventDefault();
          dernier.focus();
        } else if (!event.shiftKey && (actif === dernier || !carteRef.current.contains(actif))) {
          event.preventDefault();
          premier.focus();
        }
      }
    };
    document.addEventListener("keydown", surTouche);
    return () => document.removeEventListener("keydown", surTouche);
  }, [onTerminer, suivant, precedent]);

  // Pendant la visite, la page derrière ne défile pas (la visite, elle, la fait défiler).
  useEffect(() => {
    const precedentOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = precedentOverflow;
    };
  }, []);

  const verrou = etape.capacite !== undefined && pret && !peut(etape.capacite);
  const offre = etape.capacite ? argumentaire(etape.capacite).offre : null;
  const disposition = disposer(cadre, etape);
  const trou = decouper(cadre, disposition);

  return (
    <div className="fixed inset-0 z-[70]">
      {/* Voile : découpé autour du repère quand il y en a un, plein sinon. */}
      {trou ? (
        <div
          aria-hidden="true"
          className="pointer-events-none fixed rounded-2xl ring-2 ring-emerald-300/90 motion-safe:transition-[left,top,width,height] motion-safe:duration-200"
          style={{
            left: trou.left,
            top: trou.top,
            width: trou.right - trou.left,
            height: trou.bottom - trou.top,
            boxShadow: "0 0 0 9999px rgba(15, 23, 42, 0.6)",
          }}
        />
      ) : (
        <div aria-hidden="true" className="fixed inset-0 bg-slate-900/60" />
      )}

      <div
        ref={carteRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titreId}
        aria-describedby={texteId}
        className="fixed flex flex-col overflow-hidden rounded-3xl border border-emerald-100 bg-white p-4 text-slate-900 shadow-2xl lg:p-5"
        style={disposition.style}
      >
        <p className="shrink-0 text-xs font-semibold uppercase tracking-[0.14em] text-emerald-700">
          Visite guidée · {index + 1}/{ETAPES_VISITE.length}
        </p>
        <h2
          ref={titreRef}
          id={titreId}
          tabIndex={-1}
          className="mt-1 shrink-0 text-base font-semibold tracking-tight outline-none lg:text-lg"
        >
          {etape.titre}
        </h2>
        <div
          id={texteId}
          className="mt-1.5 min-h-0 flex-1 space-y-2 overflow-y-auto text-sm leading-6 text-slate-600"
        >
          <p>{etape.texte}</p>
          {verrou && offre ? (
            <p className="rounded-2xl border border-amber-200 bg-amber-50 px-3 py-1.5 text-amber-900">
              {etape.sansOffre ? `${etape.sansOffre} ${offre}.` : `Inclus dans l’offre ${offre}.`}
            </p>
          ) : null}
        </div>

        <ol className="mt-3 flex shrink-0 flex-wrap items-center gap-1.5" aria-hidden="true">
          {ETAPES_VISITE.map((e, i) => (
            <li
              key={e.id}
              className={cn(
                "h-1.5 rounded-full motion-safe:transition-all",
                i === index ? "w-5 bg-emerald-700" : i < index ? "w-1.5 bg-emerald-300" : "w-1.5 bg-slate-200",
              )}
            />
          ))}
        </ol>

        <div className="mt-3 flex shrink-0 items-center gap-2">
          {!derniere ? (
            <Button variant="ghost" size="sm" onClick={onTerminer}>
              Passer
            </Button>
          ) : null}
          <span className="flex-1" />
          {index > 0 ? (
            <Button variant="secondary" size="sm" onClick={precedent}>
              Précédent
            </Button>
          ) : null}
          <Button size="sm" onClick={suivant}>
            {derniere ? "Terminer" : index === 0 ? "C’est parti" : "Suivant"}
          </Button>
        </div>
      </div>
    </div>
  );
}

type Disposition = { style: CSSProperties; rangee: "bas" | "haut" | "cote" | "centre" };

/** Hauteur des onglets du bas sur téléphone (`h-16`), encoche comprise. */
const SOUS_ONGLETS = "calc(4rem + env(safe-area-inset-bottom) + 0.5rem)";
const SOUS_ENCOCHE = "calc(env(safe-area-inset-top) + 0.5rem)";

/**
 * Où poser la carte pour qu'elle ne recouvre jamais le repère.
 *
 * - Téléphone : rangée en bas, au-dessus des onglets, quand le repère est dans la moitié haute
 *   de l'écran ; en haut sinon. Compacte, elle défile en interne au-delà de 45 % de l'écran.
 * - Ordinateur : à côté du repère, à droite s'il reste la place, sinon au-dessus ou en dessous.
 * - Sans repère (accueil, fin, ou repère introuvable) : au centre.
 */
function disposer(cadre: Cadre | null, etape: EtapeVisite): Disposition {
  const vw = cadre?.vw ?? LARGEUR_ORDINATEUR;
  const vh = cadre?.vh ?? 768;
  const rect = cadre?.rect ?? null;
  const telephone = vw < LARGEUR_ORDINATEUR;
  const sansRepere = etape.cibles.length === 0 || (cadre?.decide === true && rect === null);

  if (telephone) {
    const largeur = "min(28rem, calc(100vw - 1.5rem))";
    const base: CSSProperties = { width: largeur, left: "50%", transform: "translateX(-50%)" };
    if (sansRepere) {
      return {
        rangee: "centre",
        style: { ...base, top: "50%", transform: "translate(-50%, -50%)", maxHeight: "70vh" },
      };
    }
    // Pendant la recherche, la carte attend déjà en bas : c'est là que finit presque tout repère.
    const hautVisible = rect ? Math.max(rect.top, 0) : 0;
    if (hautVisible < vh / 2) {
      return { rangee: "bas", style: { ...base, bottom: SOUS_ONGLETS, maxHeight: "45vh" } };
    }
    return { rangee: "haut", style: { ...base, top: SOUS_ENCOCHE, maxHeight: "45vh" } };
  }

  const largeur = Math.min(360, vw - 2 * MARGE);
  const borne = (valeur: number, min: number, max: number) => Math.max(min, Math.min(valeur, max));

  if (!rect || sansRepere) {
    return {
      rangee: "centre",
      style: {
        width: largeur,
        left: (vw - largeur) / 2,
        top: "50%",
        transform: "translateY(-50%)",
        maxHeight: vh - 2 * MARGE,
      },
    };
  }

  if (rect.right + ECART + largeur + MARGE <= vw) {
    return {
      rangee: "cote",
      style: {
        width: largeur,
        left: rect.right + ECART,
        top: borne(rect.top - 8, MARGE, Math.max(MARGE, vh - 340)),
        maxHeight: vh - 2 * MARGE,
      },
    };
  }

  const left = borne((rect.left + rect.right) / 2 - largeur / 2, MARGE, vw - largeur - MARGE);
  if (Math.max(rect.top, 0) >= vh / 2) {
    return {
      rangee: "haut",
      style: { width: largeur, left, bottom: vh - rect.top + ECART, maxHeight: Math.max(160, rect.top - ECART - MARGE) },
    };
  }
  return {
    rangee: "bas",
    style: {
      width: largeur,
      left,
      top: Math.min(rect.bottom, vh - 200) + ECART,
      maxHeight: Math.max(160, vh - Math.min(rect.bottom, vh - 200) - ECART - MARGE),
    },
  };
}

/**
 * Le trou du voile : le repère avec un peu d'air, coupé aux bords de l'écran et, sur
 * téléphone, au bord de la carte — un repère plus haut que l'écran reste éclairé là où il se
 * voit, sans jamais passer sous l'explication.
 */
function decouper(cadre: Cadre | null, disposition: Disposition): Zone | null {
  const rect = cadre?.rect ?? null;
  if (!cadre || !rect || disposition.rangee === "centre") return null;

  const trou: Zone = {
    left: Math.max(rect.left - BORD, 4),
    top: Math.max(rect.top - BORD, 4),
    right: Math.min(rect.right + BORD, cadre.vw - 4),
    bottom: Math.min(rect.bottom + BORD, cadre.vh - 4),
  };
  const telephone = cadre.vw < LARGEUR_ORDINATEUR;
  if (telephone && cadre.carte) {
    if (disposition.rangee === "bas") trou.bottom = Math.min(trou.bottom, cadre.carte.top - 8);
    if (disposition.rangee === "haut") trou.top = Math.max(trou.top, cadre.carte.bottom + 8);
  }
  if (trou.bottom - trou.top < 12 || trou.right - trou.left < 12) return null;
  return trou;
}
