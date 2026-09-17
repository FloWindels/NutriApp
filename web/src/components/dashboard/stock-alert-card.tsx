import Link from "next/link";
import { Card, CardHeader } from "@/components/ui/card";
import { EmptyState } from "@/components/ui/empty-state";
import { ExpiryBadge } from "@/components/ui/expiry-badge";
import { Pill } from "@/components/ui/pill";
import type { Dashboard } from "@/lib/types/api";

export type StockAlertCardProps = {
  stock: Dashboard["stock"];
};

/** Expiring / expired / low counts plus the next items to consume (brief §7). */
export function StockAlertCard({ stock }: StockAlertCardProps) {
  const expiring = stock?.expiring ?? [];
  const hasAlert =
    (stock?.expired_count ?? 0) > 0 || (stock?.expiring_count ?? 0) > 0 || (stock?.low_count ?? 0) > 0;

  return (
    <Card>
      <CardHeader
        title="Stock"
        subtitle="Ce qui doit être consommé en priorité."
        actions={
          <Link
            href="/dashboard/stock"
            className="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
          >
            Ouvrir le stock
          </Link>
        }
      />

      {hasAlert ? (
        <div className="mt-4 flex flex-wrap gap-2">
          {stock.expired_count > 0 ? (
            <Pill tone="rose" dot>
              {stock.expired_count} périmé{stock.expired_count > 1 ? "s" : ""}
            </Pill>
          ) : null}
          {stock.expiring_count > 0 ? (
            <Pill tone="amber" dot>
              {stock.expiring_count} bientôt périmé{stock.expiring_count > 1 ? "s" : ""}
            </Pill>
          ) : null}
          {stock.low_count > 0 ? (
            <Pill tone="slate" dot>
              {stock.low_count} à racheter
            </Pill>
          ) : null}
        </div>
      ) : null}

      {expiring.length === 0 ? (
        <EmptyState
          compact
          className="mt-4"
          title="Aucun produit à surveiller"
          message="Ton stock ne comporte aucun produit qui arrive à expiration."
          action={
            <Link
              href="/dashboard/stock"
              className="inline-flex h-11 items-center rounded-2xl border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
            >
              Gérer mon stock
            </Link>
          }
        />
      ) : (
        <ul className="mt-4 divide-y divide-slate-100">
          {expiring.map((item) => (
            <li key={item.id} className="flex items-center justify-between gap-3 py-2.5">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-slate-900">{item.label}</p>
                <p className="truncate text-xs text-slate-500">{item.stock_name}</p>
              </div>
              <ExpiryBadge expiresAt={item.expires_at} daysLeft={item.days_left} />
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

export default StockAlertCard;
