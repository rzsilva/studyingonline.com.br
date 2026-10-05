import { forwardRef, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode } from 'react';
import { AlertCircle, CheckCircle2, Loader2 } from 'lucide-react';

const cx = (...c: (string | false | null | undefined)[]) => c.filter(Boolean).join(' ');

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
  loading?: boolean;
};

export function Button({ variant = 'primary', loading, disabled, className, children, ...rest }: ButtonProps) {
  const styles = {
    primary: 'bg-primary text-white hover:bg-primary/90 focus-visible:ring-primary/40',
    secondary: 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 focus-visible:ring-slate-300',
    ghost: 'text-slate-600 hover:bg-slate-100 focus-visible:ring-slate-300',
    danger: 'bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-300',
  }[variant];
  return (
    <button
      className={cx(
        'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition',
        'focus-visible:outline-none focus-visible:ring-4 disabled:cursor-not-allowed disabled:opacity-60',
        styles,
        className,
      )}
      disabled={disabled || loading}
      {...rest}
    >
      {loading && <Loader2 className="h-4 w-4 animate-spin" aria-hidden />}
      {children}
    </button>
  );
}

type InputProps = InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string };

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input({ label, error, id, className, ...rest }, ref) {
  const inputId = id ?? rest.name;
  return (
    <div className={className}>
      <label htmlFor={inputId} className="mb-1.5 block text-sm font-medium text-slate-700">
        {label}
      </label>
      <input
        ref={ref}
        id={inputId}
        aria-invalid={!!error}
        aria-describedby={error ? `${inputId}-error` : undefined}
        className={cx(
          'block w-full rounded-lg border bg-white px-3 py-2.5 text-sm shadow-sm transition',
          'focus:outline-none focus:ring-4',
          error
            ? 'border-red-400 focus:border-red-500 focus:ring-red-100'
            : 'border-slate-300 focus:border-primary focus:ring-primary/15',
        )}
        {...rest}
      />
      {error && (
        <p id={`${inputId}-error`} className="mt-1 text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  );
});

export function Alert({ kind = 'error', children }: { kind?: 'error' | 'success'; children: ReactNode }) {
  const Icon = kind === 'error' ? AlertCircle : CheckCircle2;
  return (
    <div
      role={kind === 'error' ? 'alert' : 'status'}
      className={cx(
        'flex items-start gap-2 rounded-lg border px-3 py-2.5 text-sm',
        kind === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700',
      )}
    >
      <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
      <div>{children}</div>
    </div>
  );
}

export function Card({ title, children, className }: { title?: string; children: ReactNode; className?: string }) {
  return (
    <section className={cx('rounded-xl border border-slate-200 bg-white p-5 shadow-sm', className)}>
      {title && <h2 className="mb-4 text-base font-semibold text-slate-900">{title}</h2>}
      {children}
    </section>
  );
}

export function FullPageSpinner() {
  return (
    <div className="flex min-h-screen items-center justify-center" aria-busy="true">
      <Loader2 className="h-8 w-8 animate-spin text-primary" aria-label="Carregando" />
    </div>
  );
}

export { cx };
