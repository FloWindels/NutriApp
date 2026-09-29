"use client";

import { useState, type ReactNode } from "react";
import { getErrorMessage } from "@/lib/api-client";
import { messages } from "@/lib/messages";
import { capaciteRequise, estOffreRequise } from "@/lib/offres-capacites";
import { Banner } from "./banner";
import { Button } from "./button";
import { Modal } from "./modal";
import { OffreRequisePourCapacite } from "./offre-requise";

export type ConfirmDialogProps = {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  message?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  /** Rose confirm button for destructive actions. */
  danger?: boolean;
  /** May be async; the dialog stays open and shows the error on failure. */
  onConfirm: () => void | Promise<void>;
};

export function ConfirmDialog({
  open,
  onClose,
  title,
  message,
  confirmLabel = messages.confirm,
  cancelLabel = messages.cancel,
  danger = false,
  onConfirm,
}: ConfirmDialogProps) {
  const [busy, setBusy] = useState(false);
  // On garde l'erreur entière, pas son message : un 402 se raconte autrement qu'une panne.
  const [error, setError] = useState<unknown>(null);

  async function handleConfirm() {
    setBusy(true);
    setError(null);
    try {
      await onConfirm();
      onClose();
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  }

  function handleClose() {
    if (busy) return;
    setError(null);
    onClose();
  }

  // Redemander confirmation n'ouvrirait aucune porte : la question disparaît au profit de
  // l'invitation, et il ne reste qu'à fermer.
  const offreRequise = estOffreRequise(error);

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={offreRequise ? "Cette action fait partie des offres payantes" : title}
      size="sm"
      locked={busy}
      footer={
        offreRequise ? (
          <Button variant="secondary" onClick={handleClose}>
            {messages.close}
          </Button>
        ) : (
          <>
            <Button variant="secondary" onClick={handleClose} disabled={busy}>
              {cancelLabel}
            </Button>
            <Button variant={danger ? "danger" : "primary"} onClick={handleConfirm} loading={busy}>
              {confirmLabel}
            </Button>
          </>
        )
      }
    >
      {offreRequise ? (
        <OffreRequisePourCapacite capacite={capaciteRequise(error)} compact />
      ) : (
        <>
          {message ? <div className="text-sm leading-6 text-slate-600">{message}</div> : null}
          {error ? (
            <Banner tone="error" className="mt-3">
              {getErrorMessage(error)}
            </Banner>
          ) : null}
        </>
      )}
    </Modal>
  );
}

export default ConfirmDialog;
