/**
 * Signaux de la séance guidée : bips générés (Web Audio, aucun fichier), vibration et écran
 * maintenu allumé. Tout est gardé : un navigateur qui refuse l’un d’eux ne casse rien.
 *
 * Safari (iPhone, PWA) n’autorise le son qu’après un geste : `unlockAudio()` doit être appelé
 * dans le gestionnaire du clic « Démarrer ». Le contexte vit au niveau du module, il survit donc
 * à une navigation côté client (proposition → page de la séance).
 */

type AudioContextCtor = typeof AudioContext;

let contexte: AudioContext | null = null;

function ctor(): AudioContextCtor | null {
  if (typeof window === "undefined") return null;
  const w = window as unknown as { AudioContext?: AudioContextCtor; webkitAudioContext?: AudioContextCtor };
  return w.AudioContext ?? w.webkitAudioContext ?? null;
}

export function unlockAudio(): void {
  try {
    const Ctor = ctor();
    if (!Ctor) return;
    if (!contexte) contexte = new Ctor();
    if (contexte.state === "suspended") void contexte.resume();
    // Un échantillon muet joué pendant le geste déverrouille la sortie sur iOS.
    const buffer = contexte.createBuffer(1, 1, 22050);
    const source = contexte.createBufferSource();
    source.buffer = buffer;
    source.connect(contexte.destination);
    source.start(0);
  } catch {
    /* pas de son : la vibration et l’affichage suffisent */
  }
}

/** Bip court : `aigu` pour un changement d’étape, grave pour le décompte 3-2-1. */
export function beep(kind: "decompte" | "etape" | "fin" = "etape"): void {
  try {
    if (!contexte) return;
    if (contexte.state === "suspended") void contexte.resume();
    const reglages = {
      decompte: { freq: 660, duree: 0.12, volume: 0.18 },
      etape: { freq: 990, duree: 0.28, volume: 0.25 },
      fin: { freq: 1320, duree: 0.5, volume: 0.25 },
    }[kind];
    const t = contexte.currentTime;
    const osc = contexte.createOscillator();
    const gain = contexte.createGain();
    osc.type = "sine";
    osc.frequency.setValueAtTime(reglages.freq, t);
    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.exponentialRampToValueAtTime(reglages.volume, t + 0.015);
    gain.gain.exponentialRampToValueAtTime(0.0001, t + reglages.duree);
    osc.connect(gain);
    gain.connect(contexte.destination);
    osc.start(t);
    osc.stop(t + reglages.duree + 0.02);
  } catch {
    /* ignoré */
  }
}

export function vibrate(pattern: number | number[]): void {
  try {
    if (typeof navigator !== "undefined" && typeof navigator.vibrate === "function") navigator.vibrate(pattern);
  } catch {
    /* ignoré */
  }
}

/** Garde l’écran allumé tant que la fonction de nettoyage n’est pas appelée. */
export function keepScreenAwake(): () => void {
  if (typeof navigator === "undefined" || !("wakeLock" in navigator)) return () => {};
  let sentinel: WakeLockSentinel | null = null;
  let actif = true;

  const demander = async () => {
    if (!actif || document.visibilityState !== "visible") return;
    try {
      sentinel = await navigator.wakeLock.request("screen");
    } catch {
      sentinel = null;
    }
  };
  // Le verrou tombe quand l’onglet passe en arrière-plan : on le redemande au retour.
  const auRetour = () => {
    if (document.visibilityState === "visible") void demander();
  };

  void demander();
  document.addEventListener("visibilitychange", auRetour);
  return () => {
    actif = false;
    document.removeEventListener("visibilitychange", auRetour);
    void sentinel?.release().catch(() => {});
    sentinel = null;
  };
}
