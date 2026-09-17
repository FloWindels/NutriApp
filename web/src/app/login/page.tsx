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
import { setSession } from "@/lib/session";
import type { AuthResponse } from "@/lib/types/api";

const schema = z.object({
  email: z.email("Adresse e-mail invalide."),
  password: z.string().min(1, "Le mot de passe est requis."),
});

type FormValues = z.infer<typeof schema>;

export default function LoginPage() {
  return (
    <Suspense fallback={null}>
      <LoginForm />
    </Suspense>
  );
}

function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const expired = searchParams.get("expired") === "1";
  const reset = searchParams.get("reset") === "1";
  const [globalError, setGlobalError] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { email: "", password: "" },
  });

  async function onSubmit(values: FormValues) {
    setGlobalError(null);
    try {
      const response = await apiPost<AuthResponse>("/auth/login", values, { anonymous: true });
      setSession(response.token, response.user);
      router.replace("/dashboard");
    } catch (error) {
      if (isApiError(error) && error.isValidation) {
        let mapped = false;
        for (const field of ["email", "password"] as const) {
          const message = error.fieldError(field);
          if (message) {
            setError(field, { message });
            mapped = true;
          }
        }
        if (!mapped) setGlobalError(error.message || messages.loginFailed);
        return;
      }
      setGlobalError(getErrorMessage(error, messages.loginFailed));
    }
  }

  return (
    <AuthShell
      eyebrow="Bienvenue"
      title={messages.loginTitle}
      subtitle="Accède à ton espace et retrouve tous tes outils de suivi en un seul endroit."
      footer={
        <>
          Pas encore de compte ?{" "}
          <Link href="/register" className="font-semibold text-lime-700 underline-offset-4 hover:underline">
            Créer un compte
          </Link>
        </>
      }
    >
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
        {expired ? <Banner tone="warning">{messages.loginExpired}</Banner> : null}
        {reset ? <Banner tone="success">{messages.passwordReset} Tu peux te connecter.</Banner> : null}

        <Field
          label="E-mail"
          type="email"
          autoComplete="email"
          placeholder="ton@email.com"
          error={errors.email?.message}
          {...register("email")}
        />

        <div>
          <Field
            label="Mot de passe"
            type={showPassword ? "text" : "password"}
            autoComplete="current-password"
            placeholder="••••••••"
            error={errors.password?.message}
            {...register("password")}
          />
          <div className="mt-2 flex items-center justify-between text-xs">
            <button
              type="button"
              onClick={() => setShowPassword((prev) => !prev)}
              className="font-medium text-slate-500 hover:text-slate-700"
              aria-pressed={showPassword}
            >
              {showPassword ? "Masquer le mot de passe" : "Afficher le mot de passe"}
            </button>
            <Link href="/forgot-password" className="font-semibold text-lime-700 underline-offset-4 hover:underline">
              Mot de passe oublié ?
            </Link>
          </div>
        </div>

        {globalError ? <Banner tone="error">{globalError}</Banner> : null}

        <Button type="submit" block size="lg" loading={isSubmitting}>
          {isSubmitting ? "Connexion…" : "Se connecter"}
        </Button>
      </form>
    </AuthShell>
  );
}
