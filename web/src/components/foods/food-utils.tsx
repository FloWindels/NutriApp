import Image from "next/image";
import { cn } from "@/lib/cn";
import { formatGrams } from "@/lib/format";
import type { Food } from "@/lib/types/api";
import { Pill } from "@/components/ui/pill";

export const BARCODE_RE = /^\d{8,14}$/;

export function isBarcode(term: string): boolean {
  return BARCODE_RE.test(term.trim());
}

/** Brief §3.2: creator, or an untouched Open Food Facts import, may edit. */
export function canEditFood(food: Food): boolean {
  return food.is_owner || (food.source_type === "open_food_facts" && food.created_by_user_id === null);
}

export function perUnitLabel(food: Pick<Food, "per_unit">): string {
  return food.per_unit === "100ml" ? "100 ml" : "100 g";
}

/** « 1 portion ≈ 30 g (1 tranche) » or null when the food has no serving info. */
export function servingText(food: Pick<Food, "serving_size_g" | "serving_label">): string | null {
  if (!food.serving_size_g) return food.serving_label ? `Portion : ${food.serving_label}` : null;
  const label = food.serving_label && food.serving_label !== `${food.serving_size_g} g` ? ` (${food.serving_label})` : "";
  return `1 portion ≈ ${formatGrams(food.serving_size_g)}${label}`;
}

export function FoodSourcePill({ food, className }: { food: Food; className?: string }) {
  if (food.is_verified) {
    return (
      <Pill tone="emerald" dot className={className} title="Fiche vérifiée par un utilisateur">
        Vérifié
      </Pill>
    );
  }
  if (food.source_type === "open_food_facts") {
    return (
      <Pill tone="sky" dot className={className} title="Données Open Food Facts (licence ODbL)">
        Open Food Facts
      </Pill>
    );
  }
  if (food.is_owner) {
    return (
      <Pill tone="lime" dot className={className}>
        Ajouté par toi
      </Pill>
    );
  }
  return (
    <Pill tone="slate" dot className={className}>
      Ajouté par la communauté
    </Pill>
  );
}

const thumbSizes = {
  sm: "h-12 w-12 rounded-xl",
  md: "h-16 w-16 rounded-2xl",
  lg: "h-28 w-28 rounded-[1.25rem]",
} as const;

/** Product picture (Open Food Facts URL or none). Decorative: the name is always next to it. */
export function FoodThumb({ food, size = "md", className }: { food: Pick<Food, "image_url" | "name">; size?: keyof typeof thumbSizes; className?: string }) {
  return (
    <span
      className={cn(
        "relative block shrink-0 overflow-hidden border border-slate-200 bg-slate-50",
        thumbSizes[size],
        className,
      )}
      aria-hidden="true"
    >
      {food.image_url ? (
        <Image src={food.image_url} alt="" fill unoptimized sizes="112px" className="object-cover" />
      ) : (
        <span className="grid h-full w-full place-items-center text-slate-300">
          <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none">
            <path
              d="M7 3H17L19 7V19C19 20.1 18.1 21 17 21H7C5.9 21 5 20.1 5 19V7L7 3Z"
              stroke="currentColor"
              strokeWidth="1.6"
              strokeLinejoin="round"
            />
            <path d="M5 7H19M9 11H15M9 15H13" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
          </svg>
        </span>
      )}
    </span>
  );
}

export function HeartIcon({ filled, className }: { filled: boolean; className?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={cn("h-5 w-5", className)} fill={filled ? "currentColor" : "none"} aria-hidden="true">
      <path
        d="M12 20.5C12 20.5 3.5 15.2 3.5 9.3C3.5 6.6 5.6 4.5 8.2 4.5C9.8 4.5 11.2 5.3 12 6.6C12.8 5.3 14.2 4.5 15.8 4.5C18.4 4.5 20.5 6.6 20.5 9.3C20.5 15.2 12 20.5 12 20.5Z"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinejoin="round"
      />
    </svg>
  );
}
