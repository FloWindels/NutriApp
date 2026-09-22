import { useState } from "react";

/**
 * Exécute `reset` pendant le rendu lorsque `key` change.
 *
 * Remplace le `useEffect` qui réinitialise l'état local d'une modale à son ouverture :
 * React 19 signale un appel synchrone à setState dans un effet comme une erreur, car il
 * provoque un rendu supplémentaire en cascade. Ajuster l'état pendant le rendu est le
 * motif recommandé (https://react.dev/learn/you-might-not-need-an-effect).
 *
 * `reset` ne doit contenir que des setState du composant courant.
 */
export function useResetOnChange(key: string, reset: () => void): void {
  const [previous, setPrevious] = useState(key);

  if (key !== previous) {
    setPrevious(key);
    reset();
  }
}
