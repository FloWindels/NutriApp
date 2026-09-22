"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Modal } from "@/components/ui/modal";
import { apiDelete, apiGet, apiPut, getErrorMessage, isApiError } from "@/lib/api-client";
import { clearSession, getUser, setSessionUser } from "@/lib/session";
import { queryKeys } from "@/lib/query-keys";
import { useResetOnChange } from "@/lib/use-reset-on-change";
import type { AuthUser, DataEnvelope, MessageEnvelope } from "@/lib/types/api";

export type AccountModalKey = "profil" | "motdepasse" | "export" | "suppression" | null;

export type AccountModalsProps = {
  open: AccountModalKey;
  onClose: () => void;
  onDone: (message: string) => void;
};

/** Les quatre actions de compte de l'écran Paramètres (§1 et §16.4). */
export function AccountModals({ open, onClose, onDone }: AccountModalsProps) {
  return (
    <>
      <ProfileModal open={open === "profil"} onClose={onClose} onDone={onDone} />
      <PasswordModal open={open === "motdepasse"} onClose={onClose} onDone={onDone} />
      <ExportModal open={open === "export"} onClose={onClose} />
      <DeleteModal open={open === "suppression"} onClose={onClose} />
    </>
  );
}

function ProfileModal({
  open,
  onClose,
  onDone,
}: {
  open: boolean;
  onClose: () => void;
  onDone: (message: string) => void;
}) {
  const queryClient = useQueryClient();
  const current = getUser();
  const [name, setName] = useState(current?.name ?? "");
  const [email, setEmail] = useState(current?.email ?? "");
  const [error, setError] = useState<string | null>(null);

  useResetOnChange(String(open), () => {
    if (!open) return;
    const user = getUser();
    setName(user?.name ?? "");
    setEmail(user?.email ?? "");
    setError(null);
  });

  const save = useMutation({
    mutationFn: () => apiPut<DataEnvelope<AuthUser>>("/account", { name: name.trim(), email: email.trim() }),
    onSuccess: async (response) => {
      setSessionUser(response.data);
      await queryClient.invalidateQueries({ queryKey: queryKeys.me });
      onDone("Compte mis à jour.");
      onClose();
    },
    onError: (err) => setError(getErrorMessage(err)),
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Nom et e-mail"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Enregistrer
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error ? <Banner tone="error">{error}</Banner> : null}
        <Field label="Nom" value={name} onChange={(event) => setName(event.target.value)} />
        <Field
          label="Adresse e-mail"
          type="email"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
        />
      </div>
    </Modal>
  );
}

function PasswordModal({
  open,
  onClose,
  onDone,
}: {
  open: boolean;
  onClose: () => void;
  onDone: (message: string) => void;
}) {
  const [currentPassword, setCurrentPassword] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);

  useResetOnChange(String(open), () => {
    if (!open) return;
    setCurrentPassword("");
    setPassword("");
    setConfirmation("");
    setError(null);
  });

  const save = useMutation({
    mutationFn: () =>
      apiPut<MessageEnvelope>("/account/password", {
        current_password: currentPassword,
        password,
        password_confirmation: confirmation,
      }),
    onSuccess: () => {
      onDone("Mot de passe modifié.");
      onClose();
    },
    onError: (err) => setError(getErrorMessage(err)),
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Changer le mot de passe"
      description="Tes autres appareils seront déconnectés."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Changer
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error ? <Banner tone="error">{error}</Banner> : null}
        <Field
          label="Mot de passe actuel"
          type="password"
          autoComplete="current-password"
          value={currentPassword}
          onChange={(event) => setCurrentPassword(event.target.value)}
        />
        <Field
          label="Nouveau mot de passe"
          type="password"
          autoComplete="new-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
        />
        <Field
          label="Confirmation"
          type="password"
          autoComplete="new-password"
          value={confirmation}
          onChange={(event) => setConfirmation(event.target.value)}
        />
      </div>
    </Modal>
  );
}

function ExportModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [copied, setCopied] = useState(false);

  const exportQuery = useQuery({
    queryKey: queryKeys.account.export,
    queryFn: () => apiGet<DataEnvelope<Record<string, unknown>>>("/account/export"),
    enabled: open,
  });

  useResetOnChange(String(open), () => setCopied(false));

  const json = exportQuery.data ? JSON.stringify(exportQuery.data.data, null, 2) : "";

  function download() {
    const blob = new Blob([json], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `mavioh-donnees-${new Date().toISOString().slice(0, 10)}.json`;
    link.click();
    URL.revokeObjectURL(url);
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Exporter mes données"
      description="L’export contient ton profil, tes repas, ton stock, tes séances et tes réglages."
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Fermer
          </Button>
          <Button
            variant="secondary"
            disabled={!json}
            onClick={async () => {
              await navigator.clipboard.writeText(json);
              setCopied(true);
            }}
          >
            {copied ? "Copié" : "Copier"}
          </Button>
          <Button disabled={!json} onClick={download}>
            Télécharger
          </Button>
        </>
      }
    >
      {exportQuery.isPending ? <p className="text-sm text-slate-500">Préparation de l’export…</p> : null}
      {exportQuery.isError ? <Banner tone="error">{getErrorMessage(exportQuery.error)}</Banner> : null}
      {json ? (
        <pre className="max-h-80 overflow-auto rounded-2xl bg-slate-950 p-4 text-xs text-slate-100">
          {json}
        </pre>
      ) : null}
    </Modal>
  );
}

function DeleteModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);

  useResetOnChange(String(open), () => {
    if (!open) return;
    setPassword("");
    setError(null);
  });

  const remove = useMutation({
    mutationFn: () => apiDelete<MessageEnvelope>("/account", { password }),
    onSuccess: () => {
      clearSession();
      queryClient.clear();
      router.push("/login");
    },
    onError: (err) =>
      setError(
        isApiError(err) && err.isValidation
          ? (err.fieldError("password") ?? getErrorMessage(err))
          : getErrorMessage(err),
      ),
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Supprimer mon compte"
      description="Toutes tes données personnelles seront effacées. Cette action est définitive."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button variant="danger" loading={remove.isPending} onClick={() => remove.mutate()}>
            Supprimer définitivement
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error ? <Banner tone="error">{error}</Banner> : null}
        <Field
          label="Confirme avec ton mot de passe"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
        />
      </div>
    </Modal>
  );
}

export default AccountModals;
