"use client";

import Link from "next/link";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AuthShell } from "@/components/auth-shell";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { apiPost, getErrorMessage, isApiError } from "@/lib/api-client";
import { messages } from "@/lib/messages";
import type { MessageEnvelope } from "@/lib/types/api";

const schema = z.object({
  email: z.email("Adresse e-mail invalide."),
});

type FormValues = z.infer<typeof schema>;

export default function ForgotPasswordPage() {
  const [sentMessage, setSentMessage] = useState<string | null>(null);
  const [globalError, setGlobalError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { email: "" },
  });

  async function onSubmit(values: FormValues) {
    setGlobalError(null);
    try {
      const response = await apiPost<MessageEnvelope>("/auth/forgot-password", values, { anonymous: true });
      setSentMessage(response.message || messages.forgotSent);
    } catch (error) {
      if (isApiError(error) && error.isValidation && error.fieldError("email")) {
        setError("email", { message: error.fieldError("email") });
        return;
      }
      setGlobalError(getErrorMessage(error));
    }
  }

  return (
    <AuthShell
      eyebrow="Récupération"
      title={messages.forgotPasswordTitle}
      subtitle="Indique ton adresse e-mail : si un compte existe, tu recevras un lien pour choisir un nouveau mot de passe."
      footer={
        <>
          Tu t’en souviens finalement ?{" "}
          <Link href="/login" className="font-semibold text-lime-700 underline-offset-4 hover:underline">
            Se connecter
          </Link>
        </>
      }
    >
      {sentMessage ? (
        <div className="space-y-4">
          <Banner tone="success">{sentMessage}</Banner>
          <p className="text-sm leading-6 text-slate-500">
            Pense à vérifier tes courriers indésirables. Le lien reste valable une heure.
          </p>
          <Link href="/login" className="block">
            <Button variant="secondary" block>
              Retour à la connexion
            </Button>
          </Link>
        </div>
      ) : (
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          <Field
            label="E-mail"
            type="email"
            autoComplete="email"
            placeholder="ton@email.com"
            error={errors.email?.message}
            {...register("email")}
          />

          {globalError ? <Banner tone="error">{globalError}</Banner> : null}

          <Button type="submit" block size="lg" loading={isSubmitting}>
            {isSubmitting ? "Envoi…" : "Envoyer le lien"}
          </Button>
        </form>
      )}
    </AuthShell>
  );
}
