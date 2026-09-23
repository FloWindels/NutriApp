import { cn } from "@/lib/cn";
import type { ExerciseMovement, Materiel } from "@/lib/types/api";

/**
 * Illustration d'un exercice : une silhouette dessinée en SVG, animée entre deux poses.
 *
 * Tout est tracé ici, rien n'est téléchargé : aucune image tierce, aucune licence, aucun appel
 * réseau, et l'illustration fonctionne hors ligne. Le composant est une fonction pure de ses
 * props — ni état, ni effet, ni minuteur — donc aucun risque d'hydratation ni de violation de la
 * règle React 19 sur les effets.
 *
 * L'animation est portée par deux poses superposées et une bascule d'opacité en CSS ; la classe
 * `exo-figure` permet à `prefers-reduced-motion` de la figer sur la première pose (globals.css).
 */

export type ExerciseFigureProps = {
  movement: ExerciseMovement | null | undefined;
  /** Nom de l'exercice : sert de texte alternatif quand la figure est seule. */
  name?: string;
  /** Matériel réellement utilisé : ajoute l'accessoire correspondant. */
  equipment?: Materiel | null;
  size?: number;
  /** Fige la figure sur sa pose de départ (listes denses, impression). */
  still?: boolean;
  /**
   * `true` quand la figure est la seule information visuelle. Par défaut elle est décorative :
   * le nom de l'exercice est toujours affiché juste à côté, le lecteur d'écran le lit déjà.
   */
  labelled?: boolean;
  className?: string;
};

const MOVEMENT_LABELS: Record<ExerciseMovement, string> = {
  squat: "Squat",
  fente: "Fente",
  charniere_hanche: "Charnière de hanche",
  pont_hanche: "Pont de hanches",
  isolation_jambe: "Isolation des jambes",
  poussee_horizontale: "Poussée horizontale",
  poussee_verticale: "Poussée verticale",
  tirage_horizontal: "Tirage horizontal",
  tirage_vertical: "Tirage vertical",
  elevation_bras: "Élévation des bras",
  flexion_coude: "Flexion des coudes",
  extension_coude: "Extension des coudes",
  gainage_statique: "Gainage statique",
  gainage_dynamique: "Gainage dynamique",
  flexion_tronc: "Flexion du tronc",
  extension_dorsale: "Extension dorsale",
  course: "Course",
  marche: "Marche",
  velo: "Vélo",
  rameur: "Rameur",
  nage: "Nage",
  saut: "Saut",
  appuis_sur_place: "Appuis sur place",
  frappes: "Frappes",
  etirement_statique: "Étirement",
  cercles_articulaires: "Cercles articulaires",
  mobilite_colonne: "Mobilité de la colonne",
  generique: "Exercice",
};

/* ------------------------------------------------------------------ */
/* Primitives de dessin                                                */
/* ------------------------------------------------------------------ */

/** Tête. */
function Tete({ x, y, r = 5 }: { x: number; y: number; r?: number }) {
  return <circle cx={x} cy={y} r={r} />;
}

/** Segment droit (membre, tronc). */
function Os({ d, className }: { d: string; className?: string }) {
  return <path d={d} className={className} />;
}

/** Ligne de sol, commune à toutes les figures. */
function Sol() {
  return <path d="M6 58 H58" className="opacity-30" />;
}

type Pose = { children: React.ReactNode };

/** Deux poses superposées : la seconde s'efface et réapparaît en alternance. */
function Poses({ un, deux, still }: { un: Pose; deux: Pose; still: boolean }) {
  return (
    <>
      <g data-pose="1" className={still ? undefined : "exo-pose-1"}>
        {un.children}
      </g>
      <g data-pose="2" className={still ? "opacity-0" : "exo-pose-2"}>
        {deux.children}
      </g>
    </>
  );
}

/* ------------------------------------------------------------------ */
/* Les vingt-sept motifs                                               */
/* ------------------------------------------------------------------ */

function figure(movement: ExerciseMovement, still: boolean): React.ReactNode {
  switch (movement) {
    case "squat":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={32} y={12} />
                <Os d="M32 17 V34 M32 34 L26 46 L26 58 M32 34 L38 46 L38 58 M32 22 L22 26 M32 22 L42 26" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={32} y={22} />
                <Os d="M32 27 V38 M32 38 L24 44 L26 58 M32 38 L40 44 L38 58 M32 30 L22 24 M32 30 L42 24" />
              </>
            ),
          }}
        />
      );

    case "fente":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={32} y={12} />
                <Os d="M32 17 V34 M32 34 L20 46 L20 58 M32 34 L44 46 L44 58 M32 22 L24 30 M32 22 L40 30" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={32} y={20} />
                <Os d="M32 25 V40 M32 40 L18 48 L18 58 M32 40 L44 50 L48 58 M32 30 L24 38 M32 30 L40 38" />
              </>
            ),
          }}
        />
      );

    case "charniere_hanche":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={32} y={12} />
                <Os d="M32 17 V36 M32 36 L28 58 M32 36 L38 58 M32 24 L26 38 M32 24 L38 38" />
                <Os d="M22 40 H44" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={22} y={22} />
                <Os d="M26 25 L38 34 M38 34 L34 58 M38 34 L42 58 M28 28 L26 44 M28 28 L32 44" />
                <Os d="M18 46 H38" />
              </>
            ),
          }}
        />
      );

    case "pont_hanche":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={14} y={50} />
                <Os d="M19 50 H36 M36 50 L44 50 L44 58 M20 50 L20 58" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={14} y={50} />
                <Os d="M19 50 L34 40 M34 40 L44 48 L44 58 M20 50 L20 58" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "isolation_jambe":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={26} y={16} />
                <Os d="M26 21 V36 M26 36 L24 58 M26 36 L34 44 L34 56" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={26} y={16} />
                <Os d="M26 21 V36 M26 36 L24 58 M26 36 L44 38 L50 40" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "poussee_horizontale":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={16} y={30} r={4} />
                <Os d="M20 32 L46 42 M24 34 L24 44 M40 40 L40 46 M46 42 L54 46" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={16} y={38} r={4} />
                <Os d="M20 40 L46 46 M24 41 L24 46 M40 45 L40 48 M46 46 L54 48" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "poussee_verticale":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={32} y={16} />
                <Os d="M32 21 V40 M32 40 L28 58 M32 40 L36 58 M32 24 L24 20 M32 24 L40 20" />
                <Os d="M20 18 H44" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={32} y={18} />
                <Os d="M32 23 V40 M32 40 L28 58 M32 40 L36 58 M32 24 L24 10 M32 24 L40 10" />
                <Os d="M20 8 H44" />
              </>
            ),
          }}
        />
      );

    case "tirage_horizontal":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={20} y={20} />
                <Os d="M24 23 L32 34 M32 34 L28 58 M32 34 L38 58 M26 26 L48 30" />
                <Os d="M48 24 V36" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={20} y={20} />
                <Os d="M24 23 L32 34 M32 34 L28 58 M32 34 L38 58 M26 26 L34 28" />
                <Os d="M34 22 V34" />
              </>
            ),
          }}
        />
      );

    case "tirage_vertical":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Os d="M14 10 H50" />
                <Tete x={32} y={26} />
                <Os d="M32 31 V46 M32 46 L28 56 M32 46 L36 56 M32 32 L24 12 M32 32 L40 12" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Os d="M14 10 H50" />
                <Tete x={32} y={16} />
                <Os d="M32 21 V38 M32 38 L28 50 M32 38 L36 50 M32 22 L24 12 M32 22 L40 12" />
              </>
            ),
          }}
        />
      );

    case "elevation_bras":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={32} y={14} />
                <Os d="M32 19 V40 M32 40 L28 58 M32 40 L36 58 M32 22 L24 34 M32 22 L40 34" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={32} y={14} />
                <Os d="M32 19 V40 M32 40 L28 58 M32 40 L36 58 M32 22 L14 22 M32 22 L50 22" />
              </>
            ),
          }}
        />
      );

    case "flexion_coude":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={32} y={14} />
                <Os d="M32 19 V40 M32 40 L28 58 M32 40 L36 58 M32 22 L26 34 L26 44 M32 22 L38 34 L38 44" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={32} y={14} />
                <Os d="M32 19 V40 M32 40 L28 58 M32 40 L36 58 M32 22 L26 34 L30 24 M32 22 L38 34 L34 24" />
              </>
            ),
          }}
        />
      );

    case "extension_coude":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={32} y={14} />
                <Os d="M32 19 V40 M32 40 L28 58 M32 40 L36 58 M32 22 L28 12 L34 8 M32 22 L38 12 L34 8" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={32} y={14} />
                <Os d="M32 19 V40 M32 40 L28 58 M32 40 L36 58 M32 22 L28 12 L30 2 M32 22 L38 12 L36 2" />
              </>
            ),
          }}
        />
      );

    case "gainage_statique":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={14} y={36} r={4} />
                <Os d="M18 38 L48 44 M22 40 V52 M46 43 V52" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={14} y={35} r={4} />
                <Os d="M18 37 L48 44 M22 39 V52 M46 43 V52" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "gainage_dynamique":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={14} y={36} r={4} />
                <Os d="M18 38 L48 44 M22 40 V52 M46 43 V52" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={14} y={36} r={4} />
                <Os d="M18 38 L48 44 M22 40 L30 46 M46 43 V52" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "flexion_tronc":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={14} y={44} />
                <Os d="M19 44 H36 M36 44 L44 50 L44 56 M36 44 L44 44" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={24} y={30} />
                <Os d="M27 33 L38 46 M38 46 L44 50 L44 56 M28 34 L40 44" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "extension_dorsale":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={18} y={48} r={4} />
                <Os d="M22 50 H46 M22 50 L14 50 M46 50 L54 50" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={18} y={40} r={4} />
                <Os d="M22 43 Q34 52 46 44 M22 43 L12 38 M46 44 L56 38" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "course":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={34} y={12} />
                <Os d="M34 17 L30 32 M30 32 L22 44 L18 56 M30 32 L38 42 L42 56 M32 22 L42 26 M32 22 L22 18" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={34} y={12} />
                <Os d="M34 17 L30 32 M30 32 L38 40 L46 50 M30 32 L22 42 L20 56 M32 22 L22 26 M32 22 L42 18" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "marche":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={32} y={12} />
                <Os d="M32 17 V34 M32 34 L26 46 L24 58 M32 34 L38 46 L40 58 M32 22 L38 32 M32 22 L26 32" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={32} y={12} />
                <Os d="M32 17 V34 M32 34 L22 44 L20 58 M32 34 L42 44 L44 58 M32 22 L26 32 M32 22 L38 32" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "velo":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <circle cx={18} cy={48} r={8} />
                <circle cx={46} cy={48} r={8} />
                <Os d="M18 48 L30 34 L46 48 M30 34 L38 34 M26 40 L34 40" />
                <Tete x={30} y={22} r={4} />
                <Os d="M30 26 L32 34 M31 28 L38 34" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <circle cx={18} cy={48} r={8} />
                <circle cx={46} cy={48} r={8} />
                <Os d="M18 48 L30 34 L46 48 M30 34 L38 34 M26 36 L34 44" />
                <Tete x={30} y={20} r={4} />
                <Os d="M30 24 L32 34 M31 26 L38 34" />
              </>
            ),
          }}
        />
      );

    case "rameur":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Os d="M8 52 H56" />
                <Tete x={26} y={26} />
                <Os d="M26 31 L28 42 M28 42 L40 46 M28 42 L22 48 M27 32 L44 34" />
                <Os d="M44 30 V38" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Os d="M8 52 H56" />
                <Tete x={20} y={24} />
                <Os d="M20 29 L24 42 M24 42 L44 44 M24 42 L18 48 M21 30 L30 32" />
                <Os d="M30 28 V36" />
              </>
            ),
          }}
        />
      );

    case "nage":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Os d="M4 44 Q14 40 24 44 T44 44 T60 44" className="opacity-40" />
                <Tete x={22} y={34} r={4} />
                <Os d="M26 36 L48 40 M26 34 L14 26 M46 40 L54 46" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Os d="M4 46 Q14 42 24 46 T44 46 T60 46" className="opacity-40" />
                <Tete x={22} y={36} r={4} />
                <Os d="M26 38 L48 42 M26 36 L16 44 M46 42 L54 36" />
              </>
            ),
          }}
        />
      );

    case "saut":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={32} y={20} />
                <Os d="M32 25 V38 M32 38 L26 50 L26 58 M32 38 L38 50 L38 58 M32 28 L24 34 M32 28 L40 34" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={32} y={10} />
                <Os d="M32 15 V30 M32 30 L22 42 L20 48 M32 30 L42 42 L44 48 M32 18 L20 8 M32 18 L44 8" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "appuis_sur_place":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={32} y={12} />
                <Os d="M32 17 V34 M32 34 L26 44 L26 58 M32 34 L40 42 L44 50 M32 22 L24 28 M32 22 L40 16" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={32} y={12} />
                <Os d="M32 17 V34 M32 34 L24 42 L20 50 M32 34 L38 44 L38 58 M32 22 L24 16 M32 22 L40 28" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "frappes":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={28} y={14} />
                <Os d="M28 19 V36 M28 36 L22 50 L22 58 M28 36 L36 50 L36 58 M28 22 L20 20 M28 22 L40 24" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={28} y={14} />
                <Os d="M28 19 V36 M28 36 L22 50 L22 58 M28 36 L36 50 L36 58 M28 22 L22 26 M28 22 L52 20" />
                <circle cx={54} cy={20} r={3} className="opacity-40" />
              </>
            ),
          }}
        />
      );

    case "etirement_statique":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={26} y={16} />
                <Os d="M26 21 V38 M26 38 L20 52 L20 58 M26 38 L44 44 L48 52 M26 24 L34 34" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={24} y={22} />
                <Os d="M24 27 L26 40 M26 40 L20 52 L20 58 M26 40 L44 44 L48 52 M25 28 L42 42" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "cercles_articulaires":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={32} y={14} />
                <Os d="M32 19 V38 M32 38 L28 58 M32 38 L36 58 M32 22 L20 16" />
                <path d="M20 16 A12 12 0 0 1 44 16" className="opacity-40" />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={32} y={14} />
                <Os d="M32 19 V38 M32 38 L28 58 M32 38 L36 58 M32 22 L44 16" />
                <path d="M20 16 A12 12 0 0 1 44 16" className="opacity-40" />
              </>
            ),
          }}
        />
      );

    case "mobilite_colonne":
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={16} y={34} r={4} />
                <Os d="M20 36 Q32 32 44 36 M24 38 V52 M42 38 V52" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={16} y={40} r={4} />
                <Os d="M20 40 Q32 48 44 40 M24 42 V52 M42 42 V52" />
                <Sol />
              </>
            ),
          }}
        />
      );

    case "generique":
    default:
      return (
        <Poses
          still={still}
          un={{
            children: (
              <>
                <Tete x={32} y={14} />
                <Os d="M32 19 V38 M32 38 L27 58 M32 38 L37 58 M32 23 L23 30 M32 23 L41 30" />
                <Sol />
              </>
            ),
          }}
          deux={{
            children: (
              <>
                <Tete x={32} y={14} />
                <Os d="M32 19 V38 M32 38 L27 58 M32 38 L37 58 M32 23 L24 18 M32 23 L40 18" />
                <Sol />
              </>
            ),
          }}
        />
      );
  }
}

/* ------------------------------------------------------------------ */
/* Accessoire de matériel                                              */
/* ------------------------------------------------------------------ */

/**
 * Petit glyphe posé en bas à droite : c'est ce qui distingue « squat au poids du corps »,
 * « goblet squat » et « squat barre », qui partagent le même mouvement.
 */
function accessoire(equipment: Materiel | null | undefined): React.ReactNode {
  switch (equipment) {
    case "halteres":
      return <path d="M48 52 h8 M46 49 v6 M58 49 v6" />;
    case "barre":
      return <path d="M42 52 h16 M44 49 v6 M56 49 v6 M41 50 v4" />;
    case "kettlebell":
      return (
        <>
          <path d="M48 52 a4 4 0 0 1 8 0 a4 4 0 0 1 -8 0" />
          <path d="M50 49 a2 2 0 0 1 4 0" />
        </>
      );
    case "elastiques":
      return <path d="M46 54 q4 -6 6 0 t6 0" />;
    case "banc":
      return <path d="M44 51 h14 M46 51 v5 M56 51 v5" />;
    case "barre_traction":
      return <path d="M44 48 h14 M46 48 v6 M56 48 v6" />;
    case "machine":
      return <path d="M46 48 h10 v8 h-10 z M50 48 v-3" />;
    case "tapis":
      return <path d="M44 54 h14 M46 50 h10" />;
    case "velo":
      return (
        <>
          <circle cx={48} cy={53} r={3} />
          <circle cx={57} cy={53} r={3} />
          <path d="M48 53 l4 -5 l5 5" />
        </>
      );
    default:
      return null;
  }
}

/* ------------------------------------------------------------------ */

export function ExerciseFigure({
  movement,
  name,
  equipment,
  size = 44,
  still = false,
  labelled = false,
  className,
}: ExerciseFigureProps) {
  const motif: ExerciseMovement = movement ?? "generique";
  const outil = accessoire(equipment);

  return (
    <svg
      viewBox="0 0 64 64"
      width={size}
      height={size}
      className={cn(
        "exo-figure shrink-0 rounded-xl bg-emerald-50 text-emerald-800",
        className,
      )}
      fill="none"
      stroke="currentColor"
      strokeWidth={2.2}
      strokeLinecap="round"
      strokeLinejoin="round"
      role={labelled ? "img" : undefined}
      aria-label={labelled ? `Illustration : ${name ?? MOVEMENT_LABELS[motif]}` : undefined}
      aria-hidden={labelled ? undefined : true}
    >
      {figure(motif, still)}
      {outil ? <g className="opacity-70">{outil}</g> : null}
    </svg>
  );
}

export { MOVEMENT_LABELS };
