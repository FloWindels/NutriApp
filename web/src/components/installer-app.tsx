"use client";

import { useEffect, useState } from "react";

/**
 * Invite à installer Mavi'oh comme une application, sur téléphone uniquement.
 *
 * - iPhone et iPad (Safari) : pas d'installation automatique possible, on montre le geste
 *   — Partager, puis « Sur l'écran d'accueil ».
 * - Android (Chrome, Edge, Samsung) : bouton « Installer » qui ouvre la fenêtre du système.
 *
 * Rien n'apparaît si l'application est déjà installée ou si la personne a fermé l'invitation.
 */

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
};

const CLE_FERMEE = "mavioh:installation-fermee";

function lireFermee(): boolean {
  try {
    return window.localStorage.getItem(CLE_FERMEE) === "1";
  } catch {
    return false;
  }
}

function estInstallee(): boolean {
  const iosStandalone = (navigator as Navigator & { standalone?: boolean }).standalone === true;
  return iosStandalone || window.matchMedia("(display-mode: standalone)").matches;
}

function estIos(): boolean {
  const ua = navigator.userAgent;
  // Les iPad récents se présentent comme un Mac : on les reconnaît à l'écran tactile.
  return /iphone|ipad|ipod/i.test(ua) || (/macintosh/i.test(ua) && navigator.maxTouchPoints > 1);
}

export function InstallerApp({ className = "" }: { className?: string }) {
  const [mode, setMode] = useState<"ios" | "android" | null>(null);
  const [invite, setInvite] = useState<BeforeInstallPromptEvent | null>(null);

  useEffect(() => {
    if (estInstallee() || lireFermee()) return;

    if (estIos()) {
      // Lecture du navigateur au montage : rien ne peut être calculé côté serveur.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setMode("ios");
      return;
    }

    const surInvite = (event: Event) => {
      event.preventDefault();
      setInvite(event as BeforeInstallPromptEvent);
      setMode("android");
    };
    const surInstallee = () => setMode(null);
    window.addEventListener("beforeinstallprompt", surInvite);
    window.addEventListener("appinstalled", surInstallee);
    return () => {
      window.removeEventListener("beforeinstallprompt", surInvite);
      window.removeEventListener("appinstalled", surInstallee);
    };
  }, []);

  if (!mode) return null;

  const fermer = () => {
    try {
      window.localStorage.setItem(CLE_FERMEE, "1");
    } catch {
      // Stockage bloqué : l'invitation reviendra, sans gravité.
    }
    setMode(null);
  };

  const installer = async () => {
    if (!invite) return;
    await invite.prompt();
    await invite.userChoice.catch(() => null);
    setInvite(null);
    setMode(null);
  };

  return (
    <div
      data-tour="installer-app"
      className={`flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950 lg:hidden ${className}`}
    >
      <div className="min-w-0 flex-1 space-y-1">
        <p className="font-semibold">Installe Mavi’oh sur ton téléphone</p>
        {mode === "ios" ? (
          <p className="text-emerald-900/80">
            Touche{" "}
            <svg viewBox="0 0 24 24" fill="none" className="inline h-4 w-4 align-text-bottom" aria-label="Partager">
              <path
                d="M12 15V3M12 3L8 7M12 3L16 7M6 11H5C4.44772 11 4 11.4477 4 12V20C4 20.5523 4.44772 21 5 21H19C19.5523 21 20 20.5523 20 20V12C20 11.4477 19.5523 11 19 11H18"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
              />
            </svg>{" "}
            <strong>Partager</strong> en bas de Safari, puis <strong>« Sur l’écran d’accueil »</strong>.
            Mavi’oh s’ouvrira alors comme une application.
          </p>
        ) : (
          <p className="text-emerald-900/80">Une icône sur ton écran d’accueil, en plein écran.</p>
        )}
        {mode === "android" ? (
          <button
            type="button"
            onClick={installer}
            className="mt-1 inline-flex h-10 items-center rounded-xl bg-emerald-700 px-4 font-semibold text-white"
          >
            Installer l’application
          </button>
        ) : null}
      </div>
      <button
        type="button"
        onClick={fermer}
        aria-label="Ne plus afficher"
        className="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-emerald-900/60 hover:bg-emerald-100"
      >
        <svg viewBox="0 0 24 24" fill="none" className="h-4 w-4" aria-hidden="true">
          <path d="M6 6L18 18M18 6L6 18" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
        </svg>
      </button>
    </div>
  );
}
