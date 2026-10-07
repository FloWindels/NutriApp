import { Suspense } from "react";
import { SessionDetail } from "@/components/sport/session-detail";
import { SkeletonCard } from "@/components/ui/skeleton";

type SessionPageProps = {
  params: Promise<{ id: string }>;
};

/** Page d'une séance : déroulé, séance guidée, validation des exercices et bilan. */
export default async function SportSessionPage({ params }: SessionPageProps) {
  const { id } = await params;

  // SessionDetail lit `?guide=1` (useSearchParams) : la limite Suspense est requise.
  return (
    <Suspense fallback={<SkeletonCard />}>
      <SessionDetail sessionId={id} />
    </Suspense>
  );
}
