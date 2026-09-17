"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AuthShell } from "@/components/auth-shell";
import { Banner } from "@/components/ui/banner";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { apiPost, getErrorMessage, isApiError } from "@/lib/api-client";
import { BRAND, messages } from "@/lib/messages";
import { setSession } from "@/lib/session";
import type { AuthResponse, RegisterInput } from "@/lib/types/api";

const schema = z
  .object({
    name: z.string().trim().min(2, "Ton nom doit contenir au moins 2 caractères."),
    email: z.email("Adresse e-mail invalide."),
    password: z.string().min(8, "Le mot de passe doit contenir au moins 8 caractères."),
    password_confirmation: z.string().min(1, "Confirme ton mot de passe."),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: "Les deux mots de passe ne correspondent pas.",
    path: ["password_confirmation"],
  });

type FormValues = z.infer<typeof schema>;
const FIELDS = ["name", "email", "password", "password_confirmation"] as const;

export default function RegisterPage() {
  const router = useRouter();
  const [globalError, setGlobalError] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { name: "", email: "", password: "", password_confirmation: "" },
  });

  async function onSubmit(values: FormValues) {
    setGlobalError(null);
    const body: RegisterInput = values;
    try {
      const response = await apiPost<AuthResponse>("/auth/register", body, { anonymous: true });
      setSession(response.token, response.user);
      router.replace("/dashboard");
    } catch (error) {
      if (isApiError(error) && error.isValidation) {
        let mapped = false;
        for (const field of FIELDS) {
          const message = error.fieldError(field);
          if (message) {
            setError(field, { message });
            mapped = true;
          }
        }
        if (!mapped) setGlobalError(error.message || messages.registerFailed);
        return;
      }
      setGlobalError(getErrorMessage(error, messages.registerFailed));
    }
  }

  return (
    <AuthShell
      reverse
      eyebrow={`Rejoins ${BRAND}`}
      title={messages.registerTitle}
      subtitle="Crée ton espace et retrouve tes données sur le web comme sur mobile."
      heroTitle="Inscris-toi pour suivre tes repas, ton stock, tes séances et tes objectifs."
      heroText="Un seul compte pour l’application mobile et le web : tes données restent synchronisées."
      footer={
        <>
          Déjà un compte ?{" "}
          <Link href="/login" className="font-semibold text-lime-700 underline-offset-4 hover:underline">
            Se connecter
          </Link>
        </>
      }
    >
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
        <Field
          label="Nom"
          type="text"
          autoComplete="name"
          placeholder="Ton prénom ou ton nom"
          error={errors.name?.message}
          {...register("name")}
        />
        <Field
          label="E-mail"
          type="email"
          autoComplete="email"
          placeholder="ton@email.com"
          error={errors.email?.message}
          {...register("email")}
        />
        <Field
          label="Mot de passe"
          type={showPassword ? "text" : "password"}
          autoComplete="new-password"
          placeholder="Minimum 8 caractères"
          error={errors.password?.message}
          {...register("password")}
        />
        <Field
          label="Confirmation du mot de passe"
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
          {isSubmitting ? "Création…" : "Créer mon compte"}
        </Button>
      </form>
    </AuthShell>
  );
}
