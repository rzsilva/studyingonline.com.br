import { forwardRef, useId, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { useQuery } from '@tanstack/react-query';
import { http } from '../api/client';
import { cx } from './ui';

export interface Opcao {
  id: number | string;
  nome: string;
}

/** Listas auxiliares (GET /listas/{nome}), com cache. */
export function useLista(nome: string | null, params = '') {
  return useQuery({
    queryKey: ['lista', nome, params],
    queryFn: () => http.get<Opcao[]>(`/listas/${nome}${params}`),
    enabled: !!nome,
    staleTime: 60_000,
  });
}

const fieldCls = (error?: string) =>
  cx(
    'block w-full rounded-lg border bg-white px-3 py-2.5 text-sm shadow-sm transition focus:outline-none focus:ring-4',
    error ? 'border-red-400 focus:border-red-500 focus:ring-red-100' : 'border-slate-300 focus:border-primary focus:ring-primary/15',
  );

function Label({ id, label }: { id: string; label: string }) {
  return (
    <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-slate-700">
      {label}
    </label>
  );
}

function Error({ id, error }: { id: string; error?: string }) {
  return error ? <p id={`${id}-error`} className="mt-1 text-xs text-red-600">{error}</p> : null;
}

type SelectProps = SelectHTMLAttributes<HTMLSelectElement> & {
  label: string;
  error?: string;
  options: Opcao[];
  placeholder?: string;
};

export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
  { label, error, options, placeholder = 'Selecione…', id, className, ...rest },
  ref,
) {
  const autoId = useId();
  const fid = id ?? rest.name ?? autoId;
  return (
    <div className={className}>
      <Label id={fid} label={label} />
      <select ref={ref} id={fid} aria-invalid={!!error} className={fieldCls(error)} {...rest}>
        <option value="">{placeholder}</option>
        {options.map((o) => (
          <option key={o.id} value={o.id}>{o.nome}</option>
        ))}
      </select>
      <Error id={fid} error={error} />
    </div>
  );
});

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; error?: string };

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea({ label, error, id, className, ...rest }, ref) {
  const autoId = useId();
  const fid = id ?? rest.name ?? autoId;
  return (
    <div className={className}>
      <Label id={fid} label={label} />
      <textarea ref={ref} id={fid} rows={3} aria-invalid={!!error} className={fieldCls(error)} {...rest} />
      <Error id={fid} error={error} />
    </div>
  );
});

export const Checkbox = forwardRef<HTMLInputElement, { label: string; className?: string } & React.InputHTMLAttributes<HTMLInputElement>>(
  function Checkbox({ label, className, id, ...rest }, ref) {
    const autoId = useId();
    const fid = id ?? rest.name ?? autoId;
    return (
      <label htmlFor={fid} className={cx('flex cursor-pointer items-center gap-2 text-sm text-slate-700', className)}>
        <input ref={ref} id={fid} type="checkbox" className="h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary/30" {...rest} />
        {label}
      </label>
    );
  },
);

/** Filtro de seleção compacto para barras de ferramentas. */
export function FilterSelect({
  value,
  onChange,
  options,
  placeholder,
  label,
}: {
  value: string;
  onChange: (v: string) => void;
  options: Opcao[];
  placeholder: string;
  label: string;
}) {
  return (
    <select
      aria-label={label}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15"
    >
      <option value="">{placeholder}</option>
      {options.map((o) => (
        <option key={o.id} value={o.id}>{o.nome}</option>
      ))}
    </select>
  );
}
