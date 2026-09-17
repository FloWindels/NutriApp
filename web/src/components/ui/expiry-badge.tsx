import { diffDays, formatDate, todayIso } from "@/lib/format";
import type { ExpiryKind, ExpiryStatus } from "@/lib/types/api";
import { Pill, type PillTone } from "./pill";

export type ExpiryBadgeProps = {
  /** Server-computed status (preferred). */
  status?: ExpiryStatus | null;
  daysLeft?: number | null;
  expiresAt?: string | null;
  kind?: ExpiryKind;
  className?: string;
};

/** Derives a status client-side when the server one is missing. */
export function deriveExpiryStatus(expiresAt: string | null | undefined, kind: ExpiryKind = "dlc"): ExpiryStatus {
  if (!expiresAt) return "inconnu";
  const days = diffDays(todayIso(), expiresAt);
  if (days < 0) return kind === "ddm" ? "ddm_depassee" : "perime";
  if (days === 0) return "aujourdhui";
  if (days <= 3) return "bientot";
  return "ok";
}

export function ExpiryBadge({ status, daysLeft, expiresAt, kind = "dlc", className }: ExpiryBadgeProps) {
  const effective = status ?? deriveExpiryStatus(expiresAt, kind);
  const days = daysLeft ?? (expiresAt ? diffDays(todayIso(), expiresAt) : null);

  let tone: PillTone = "slate";
  let text = "Sans date";
  let title = "Aucune date de péremption";

  switch (effective) {
    case "perime":
      tone = "rose";
      text = "Périmé";
      title = expiresAt ? `Périmé depuis le ${formatDate(expiresAt)}` : "Périmé";
      break;
    case "ddm_depassee":
      tone = "violet";
      text = "DDM dépassée";
      title = "Date de durabilité minimale dépassée : souvent encore consommable, vérifie l’aspect et l’odeur.";
      break;
    case "aujourdhui":
      tone = "amber";
      text = "Aujourd’hui";
      title = "À consommer aujourd’hui";
      break;
    case "bientot":
      tone = "amber";
      text = days === 1 ? "Demain" : days !== null ? `J-${days}` : "Bientôt";
      title = expiresAt ? `À consommer avant le ${formatDate(expiresAt)}` : "Bientôt périmé";
      break;
    case "ok":
      tone = "slate";
      text = days !== null && days <= 30 ? `J-${days}` : expiresAt ? formatDate(expiresAt) : "OK";
      title = expiresAt ? `${kind === "ddm" ? "DDM" : "DLC"} : ${formatDate(expiresAt)}` : "OK";
      break;
    default:
      break;
  }

  return (
    <Pill tone={tone} dot className={className} title={title}>
      {text}
    </Pill>
  );
}

export default ExpiryBadge;
