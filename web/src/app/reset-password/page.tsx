"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AuthShell } from "@/components/auth-shell";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { apiPost, getErrorMessage, isApiError } from "@/lib/api-client";
import { messages } from "@/lib/messages";
import type { MessageEnvelope, ResetPasswordInput } from "@/lib/types/api";

const schema = z
  .object({
    email: z.email("Adresse e-mail invalide."),
    password: z.string().min(8, "Le mot de passe doit contenir au moins 8 caractères."),
    password_confirmation: z.string().min(1, "Confirme ton mot de passe."),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: "Les deux mots de passe ne correspondent pas.",
    path: ["password_confirmation"],
  });

type FormValues = z.infer<typeof schema>;

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={null}>
      <ResetPasswordForm />
    </Suspense>
  );
}

function ResetPasswordForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get("token") ?? "";
  const emailFromLink = searchParams.get("email") ?? "";
  const [globalError, setGlobalError] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { email: emailFromLink, password: "", password_confirmation: "" },
  });

  async function onSubmit(values: FormValues) {
    setGlobalError(null);
    const body: ResetPasswordInput = { ...values, token };
    try {
      await apiPost<MessageEnvelope>("/auth/reset-password", body, { anonymous: true });
      router.replace("/login?reset=1");
    } catch (error) {
      if (isApiError(error) && error.isValidation) {
        let mapped = false;
        for (const field of ["email", "password", "password_confirmation"] as const) {
          const message = error.fieldError(field);
          if (message) {
            setError(field, { message });
            mapped = true;
          }
        }
        const tokenError = error.fieldError("token");
        if (!mapped || tokenError) setGlobalError(tokenError ?? error.message);
        return;
      }
      setGlobalError(getErrorMessage(error));
    }
  }

  const missingToken = token.length === 0;

  return (
    <AuthShell
      eyebrow="Sécurité"
      title={messages.resetPasswordTitle}
      subtitle="Choisis un nouveau mot de passe pour ton compte."
      footer={
        <>
          Lien expiré ?{" "}
          <Link href="/forgot-password" className="font-semibold text-lime-700 underline-offset-4 hover:underline">
            Demander un nouveau lien
          </Link>
        </>
      }
    >
      {missingToken ? (
        <div className="space-y-4">
          <Banner tone="error">
            Ce lien est incomplet ou invalide. Redemande un e-mail de réinitialisation depuis la page « Mot de passe oublié ».
          </Banner>
          <Link href="/forgot-password" className="block">
            <Button variant="secondary" block>
              Mot de passe oublié
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
          <Field
            label="Nouveau mot de passe"
            type={showPassword ? "text" : "password"}
            autoComplete="new-password"
            placeholder="Minimum 8 caractères"
            error={errors.password?.message}
            {...register("password")}
          />
          <Field
            label="Confirmation"
            type={showPassword ? "text" : "password"}
            autoComplete="new-password"
            placeholder="Confirme ton mot de passe"
            error={errors.password_confirmation?.message}
            {...register("password_confirmation")}
          />
          <button
            type="button"
            onClick={() => setShowPassword((prev) => !prev)}
            className="text-xs font-medium text-slate-500 hover:text-slate-700"
            aria-pressed={showPassword}
          >
            {showPassword ? "Masquer les mots de passe" : "Afficher les mots de passe"}
          </button>

          {globalError ? <Banner tone="error">{globalError}</Banner> : null}

          <Button type="submit" block size="lg" loading={isSubmitting}>
            {isSubmitting ? "Enregistrement…" : "Réinitialiser le mot de passe"}
          </Button>
        </form>
      )}
    </AuthShell>
  );
}
