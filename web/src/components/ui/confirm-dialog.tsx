"use client";

import { useState, type ReactNode } from "react";
import { getErrorMessage } from "@/lib/api-client";
import { messages } from "@/lib/messages";
import { Banner } from "./banner";
import { Button } from "./button";
import { Modal } from "./modal";

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
  const [error, setError] = useState<string | null>(null);

  async function handleConfirm() {
    setBusy(true);
    setError(null);
    try {
      await onConfirm();
      onClose();
    } catch (err) {
      setError(getErrorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  function handleClose() {
    if (busy) return;
    setError(null);
    onClose();
  }

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={title}
      size="sm"
      locked={busy}
      footer={
        <>
          <Button variant="secondary" onClick={handleClose} disabled={busy}>
            {cancelLabel}
          </Button>
          <Button variant={danger ? "danger" : "primary"} onClick={handleConfirm} loading={busy}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      {message ? <div className="text-sm leading-6 text-slate-600">{message}</div> : null}
      {error ? (
        <Banner tone="error" className="mt-3">
          {error}
        </Banner>
      ) : null}
    </Modal>
  );
}

export default ConfirmDialog;
