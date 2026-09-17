import { useId, type ComponentPropsWithRef, type ReactNode } from "react";
import { cn } from "@/lib/cn";

/**
 * Form controls that plug straight into react-hook-form:
 *   <Field label="E-mail" type="email" error={errors.email?.message} {...register("email")} />
 * React 19 forwards `ref` as a regular prop, so `register()` works without forwardRef.
 */

export type FieldWrapperProps = {
  label?: ReactNode;
  hint?: ReactNode;
  error?: ReactNode;
  required?: boolean;
  htmlFor?: string;
  className?: string;
  children: ReactNode;
};

export function FieldWrapper({ label, hint, error, required, htmlFor, className, children }: FieldWrapperProps) {
  return (
    <div className={cn("min-w-0", className)}>
      {label ? (
        <label htmlFor={htmlFor} className="mb-1.5 block text-sm font-medium text-slate-700">
          {label}
          {required ? <span className="ml-0.5 text-rose-600" aria-hidden="true">*</span> : null}
        </label>
      ) : null}
      {children}
      {error ? (
        <p className="mt-1.5 text-xs font-medium text-rose-600" role="alert">
          {error}
        </p>
      ) : hint ? (
        <p className="mt-1.5 text-xs text-slate-500">{hint}</p>
      ) : null}
    </div>
  );
}

export const inputClassName =
  "w-full rounded-2xl border bg-white px-4 py-3 text-slate-900 outline-none transition placeholder:text-slate-400 focus:ring-4 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500";

export function inputStateClassName(hasError: boolean): string {
  return hasError
    ? "border-rose-300 focus:border-rose-500 focus:ring-rose-500/10"
    : "border-slate-200 focus:border-lime-600 focus:ring-lime-600/10";
}

type CommonFieldProps = {
  label?: ReactNode;
  hint?: ReactNode;
  error?: ReactNode;
  wrapperClassName?: string;
};

export type FieldProps = ComponentPropsWithRef<"input"> & CommonFieldProps;

export function Field({ label, hint, error, wrapperClassName, className, id, required, ...props }: FieldProps) {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  return (
    <FieldWrapper label={label} hint={hint} error={error} required={required} htmlFor={inputId} className={wrapperClassName}>
      <input
        id={inputId}
        aria-invalid={error ? true : undefined}
        required={required}
        className={cn(inputClassName, inputStateClassName(Boolean(error)), className)}
        {...props}
      />
    </FieldWrapper>
  );
}

export type TextareaFieldProps = ComponentPropsWithRef<"textarea"> & CommonFieldProps;

export function TextareaField({ label, hint, error, wrapperClassName, className, id, required, rows = 3, ...props }: TextareaFieldProps) {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  return (
    <FieldWrapper label={label} hint={hint} error={error} required={required} htmlFor={inputId} className={wrapperClassName}>
      <textarea
        id={inputId}
        rows={rows}
        aria-invalid={error ? true : undefined}
        required={required}
        className={cn(inputClassName, inputStateClassName(Boolean(error)), "resize-y", className)}
        {...props}
      />
    </FieldWrapper>
  );
}

export type SelectFieldProps = ComponentPropsWithRef<"select"> & CommonFieldProps;

export function SelectField({ label, hint, error, wrapperClassName, className, id, required, children, ...props }: SelectFieldProps) {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  return (
    <FieldWrapper label={label} hint={hint} error={error} required={required} htmlFor={inputId} className={wrapperClassName}>
      <select
        id={inputId}
        aria-invalid={error ? true : undefined}
        required={required}
        className={cn(inputClassName, inputStateClassName(Boolean(error)), "appearance-none pr-10", className)}
        {...props}
      >
        {children}
      </select>
    </FieldWrapper>
  );
}

export type CheckboxFieldProps = ComponentPropsWithRef<"input"> & {
  label: ReactNode;
  hint?: ReactNode;
  error?: ReactNode;
  wrapperClassName?: string;
};

export function CheckboxField({ label, hint, error, wrapperClassName, className, id, ...props }: CheckboxFieldProps) {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  return (
    <div className={cn("min-w-0", wrapperClassName)}>
      <label htmlFor={inputId} className="flex cursor-pointer items-start gap-3 text-sm text-slate-700">
        <input
          id={inputId}
          type="checkbox"
          aria-invalid={error ? true : undefined}
          className={cn("mt-0.5 h-5 w-5 shrink-0 rounded-md border-slate-300 accent-emerald-700", className)}
          {...props}
        />
        <span>{label}</span>
      </label>
      {error ? (
        <p className="mt-1.5 text-xs font-medium text-rose-600" role="alert">
          {error}
        </p>
      ) : hint ? (
        <p className="mt-1.5 text-xs text-slate-500">{hint}</p>
      ) : null}
    </div>
  );
}

export default Field;
