import { SessionDetail } from "@/components/sport/session-detail";

type SessionPageProps = {
  params: Promise<{ id: string }>;
};

/** Page d'une séance : déroulé, validation des exercices et bilan. */
export default async function SportSessionPage({ params }: SessionPageProps) {
  const { id } = await params;

  return <SessionDetail sessionId={id} />;
}
