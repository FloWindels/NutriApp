"use client";

import { Card } from "@/components/ui/card";
import { SelectField } from "@/components/ui/field";
import type { Magasin, ShoppingTri } from "@/lib/types/api";

/**
 * « Je vais où ? » — le seul réglage qui change tout le reste de l'écran.
 *
 * Le choix est enregistré comme magasin préféré : quelqu'un qui fait toujours ses courses au même
 * endroit ne doit pas le redire chaque semaine. « Aucun magasin » est une réponse normale, pas un
 * champ vide : la liste existe très bien sans prix.
 */
export function MagasinChoix({
  magasins,
  magasinId,
  onMagasin,
  tri,
  onTri,
  enCours = false,
}: {
  magasins: Magasin[];
  magasinId: number | null;
  onMagasin: (id: number | null) => void;
  tri: ShoppingTri;
  onTri: (tri: ShoppingTri) => void;
  enCours?: boolean;
}) {
  const actifs = magasins.filter((magasin) => magasin.actif);

  return (
    <Card padding="md">
      <div className="grid gap-3 sm:grid-cols-2">
        <SelectField
          label="Magasin"
          hint="Ton choix est retenu pour la prochaine fois."
          value={magasinId === null ? "" : String(magasinId)}
          disabled={enCours || actifs.length === 0}
          onChange={(event) => onMagasin(event.target.value === "" ? null : Number(event.target.value))}
        >
          <option value="">Aucun magasin</option>
          {actifs.map((magasin) => (
            <option key={magasin.id} value={magasin.id}>
              {magasin.enseigne_libelle}
              {magasin.nom !== magasin.enseigne_libelle ? ` · ${magasin.nom}` : ""}
            </option>
          ))}
        </SelectField>

        <SelectField
          label="Ordre de la liste"
          hint={
            magasinId === null
              ? "Choisis un magasin pour ranger ta liste dans l’ordre des rayons."
              : "Les rayons sont dans l’ordre où tu les traverses."
          }
          value={tri}
          disabled={magasinId === null}
          onChange={(event) => onTri(event.target.value === "rayon" ? "rayon" : "ajout")}
        >
          <option value="rayon">Par rayon</option>
          <option value="ajout">Par ordre d’ajout</option>
        </SelectField>
      </div>
    </Card>
  );
}

export default MagasinChoix;
