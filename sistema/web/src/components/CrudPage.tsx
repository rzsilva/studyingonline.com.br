import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { ApiError, apiWithMeta, http } from '../api/client';
import { useAuth } from '../auth/AuthProvider';
import type { Me } from '../lib/types';
import { Alert, Button, Input, cx } from './ui';
import { Checkbox, FilterSelect, Select, Textarea, useLista, type Opcao } from './form';
import { Modal, useFeedback } from './overlay';

export type Row = Record<string, unknown> & { id: number };

export interface FieldDef {
  name: string;
  label: string;
  type?: 'text' | 'textarea' | 'number' | 'decimal' | 'date' | 'time' | 'checkbox' | 'select' | 'url';
  required?: boolean;
  /** lista auxiliar (GET /listas/{lista}) para selects */
  lista?: string;
  /** query string extra da lista, podendo depender do formulário */
  listaParams?: (form: Record<string, unknown>) => string | null;
  options?: Opcao[];
  wide?: boolean;
  help?: string;
  /** esconde o campo conforme o formulário */
  hidden?: (form: Record<string, unknown>) => boolean;
}

export interface ColumnDef {
  key: string;
  label: string;
  render?: (row: Row) => ReactNode;
  className?: string;
}

export interface FilterDef {
  param: string;
  label: string;
  lista?: string;
  listaParams?: (filters: Record<string, string>) => string | null;
  options?: Opcao[];
}

export interface CrudConfig {
  title: string;
  subtitle?: string;
  endpoint: string;
  singular: string;
  columns: ColumnDef[];
  fields: FieldDef[];
  filters?: FilterDef[];
  canWrite?: (u: Me) => boolean;
  canDelete?: (u: Me) => boolean;
  defaults?: (filters: Record<string, string>) => Record<string, unknown>;
  rowActions?: (row: Row, reload: () => void) => ReactNode;
  /** exige um filtro antes de listar (ex.: escolher o curso) */
  requiredFilter?: string;
  searchable?: boolean;
  formSize?: 'md' | 'lg' | 'xl';
}

export const fmt = {
  date: (v: unknown) => (v ? new Date(String(v).slice(0, 10) + 'T00:00:00').toLocaleDateString('pt-BR') : '—'),
  money: (v: unknown) => (v === null || v === undefined || v === '' ? '—' : Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })),
  num: (v: unknown) => (v === null || v === undefined || v === '' ? '—' : Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 })),
  bool: (v: unknown) => (v ? 'Sim' : 'Não'),
  time: (v: unknown) => (v ? String(v).slice(0, 5) : '—'),
};

function useDebounced<T>(value: T, ms = 350) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

function toFormValue(f: FieldDef, v: unknown): unknown {
  if (v === null || v === undefined) return f.type === 'checkbox' ? false : '';
  if (f.type === 'date') return String(v).slice(0, 10);
  if (f.type === 'time') return String(v).slice(0, 5);
  return v;
}

function FieldInput({ f, value, error, form, onChange }: {
  f: FieldDef; value: unknown; error?: string; form: Record<string, unknown>; onChange: (v: unknown) => void;
}) {
  const params = f.listaParams ? f.listaParams(form) : '';
  const lista = useLista(f.lista && params !== null ? f.lista : null, params ?? '');
  const label = f.required ? `${f.label} *` : f.label;
  const common = { name: f.name, id: `f-${f.name}`, error, className: f.wide ? 'sm:col-span-2' : undefined };

  switch (f.type) {
    case 'textarea':
      return <Textarea {...common} label={label} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} />;
    case 'checkbox':
      return <Checkbox name={f.name} id={`f-${f.name}`} label={f.label} className="sm:col-span-2" checked={!!value} onChange={(e) => onChange(e.target.checked)} />;
    case 'select':
      return <Select {...common} label={label} options={f.options ?? lista.data ?? []} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} />;
    default:
      return (
        <div className={common.className}>
          <Input
            {...common}
            className={undefined}
            label={label}
            type={f.type === 'decimal' || f.type === 'text' || !f.type ? 'text' : f.type === 'url' ? 'url' : f.type}
            inputMode={f.type === 'decimal' ? 'decimal' : undefined}
            value={String(value ?? '')}
            onChange={(e) => onChange(e.target.value)}
          />
          {f.help && !error && <p className="mt-1 text-xs text-slate-500">{f.help}</p>}
        </div>
      );
  }
}

export function CrudPage({ config, children }: { config: CrudConfig; children?: ReactNode }) {
  const { user } = useAuth();
  const qc = useQueryClient();
  const { toast, confirm } = useFeedback();
  const [filters, setFilters] = useState<Record<string, string>>({});
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Row | 'new' | null>(null);
  const [form, setForm] = useState<Record<string, unknown>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const dq = useDebounced(q);

  const canWrite = !!user && (config.canWrite ? config.canWrite(user) : user.perfilId === 1);
  const canDelete = !!user && (config.canDelete ? config.canDelete(user) : canWrite);
  const blocked = !!config.requiredFilter && !filters[config.requiredFilter];

  const qs = useMemo(() => {
    const p = new URLSearchParams({ page: String(page), perPage: '25' });
    if (dq) p.set('q', dq);
    Object.entries(filters).forEach(([k, v]) => v && p.set(k, v));
    return p.toString();
  }, [page, dq, filters]);

  const list = useQuery({
    queryKey: [config.endpoint, qs],
    queryFn: () => apiWithMeta<Row[]>(`${config.endpoint}?${qs}`),
    enabled: !blocked,
    placeholderData: keepPreviousData,
  });
  const rows = list.data?.data ?? [];
  const total = list.data?.meta?.total ?? rows.length;

  const reload = () => qc.invalidateQueries({ queryKey: [config.endpoint] });

  const openForm = (row: Row | 'new') => {
    const base = row === 'new' ? { ...(config.defaults?.(filters) ?? {}) } : row;
    setForm(Object.fromEntries(config.fields.map((f) => [f.name, toFormValue(f, base[f.name])])));
    setErrors({});
    setFormError(null);
    setEditing(row);
  };

  const save = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = {};
      for (const f of config.fields) {
        if (f.hidden?.(form)) continue;
        const v = form[f.name];
        payload[f.name] = f.type === 'checkbox' ? !!v : v === '' ? null : v;
      }
      return editing === 'new'
        ? http.post<Row>(config.endpoint, payload)
        : http.put<Row>(`${config.endpoint}/${(editing as Row).id}`, payload);
    },
    onSuccess: () => {
      toast(`${config.singular} salvo(a) com sucesso.`);
      setEditing(null);
      reload();
    },
    onError: (e) => {
      if (e instanceof ApiError && e.status === 422) {
        setErrors(e.fields);
        setFormError(Object.keys(e.fields).length ? null : e.message);
      } else setFormError(e instanceof ApiError ? e.message : 'Falha ao salvar.');
    },
  });

  const remove = async (row: Row) => {
    if (!(await confirm(`Excluir este registro de ${config.singular.toLowerCase()}? Esta ação não pode ser desfeita.`, { danger: true }))) return;
    try {
      await http.del(`${config.endpoint}/${row.id}`);
      toast('Registro excluído.');
      reload();
    } catch (e) {
      toast(e instanceof ApiError ? e.message : 'Falha ao excluir.', 'error');
    }
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const missing = Object.fromEntries(
      config.fields
        .filter((f) => f.required && !f.hidden?.(form) && (form[f.name] === '' || form[f.name] === null || form[f.name] === undefined))
        .map((f) => [f.name, 'Campo obrigatório.']),
    );
    if (Object.keys(missing).length) return setErrors(missing);
    save.mutate();
  };

  const pages = Math.max(1, Math.ceil(total / 25));

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">{config.title}</h1>
          {config.subtitle && <p className="mt-1 text-sm text-slate-500">{config.subtitle}</p>}
        </div>
        {canWrite && !blocked && (
          <Button onClick={() => openForm('new')}>
            <Plus className="h-4 w-4" /> Novo(a) {config.singular.toLowerCase()}
          </Button>
        )}
      </div>

      {children}

      <div className="flex flex-wrap gap-2">
        {config.searchable !== false && (
          <div className="relative min-w-[220px] flex-1 sm:max-w-xs">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              type="search"
              aria-label="Buscar"
              placeholder="Buscar…"
              value={q}
              onChange={(e) => { setQ(e.target.value); setPage(1); }}
              className="w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15"
            />
          </div>
        )}
        {config.filters?.map((f) => (
          <FilterControl key={f.param} f={f} filters={filters} onChange={(v) => {
            setFilters((s) => {
              const next = { ...s, [f.param]: v };
              // filtros dependentes (ex.: módulo depende do curso) são limpos
              config.filters?.forEach((o) => { if (o.listaParams && o.param !== f.param) delete next[o.param]; });
              return next;
            });
            setPage(1);
          }} />
        ))}
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {blocked ? (
          <p className="p-8 text-center text-sm text-slate-500">
            Selecione {config.filters?.find((f) => f.param === config.requiredFilter)?.label.toLowerCase()} para ver os registros.
          </p>
        ) : list.isError ? (
          <div className="p-4"><Alert>{(list.error as Error).message}</Alert></div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50">
                <tr>
                  {config.columns.map((c) => (
                    <th key={c.key} scope="col" className={cx('px-4 py-3 text-left font-semibold text-slate-600', c.className)}>{c.label}</th>
                  ))}
                  <th scope="col" className="px-4 py-3 text-right"><span className="sr-only">Ações</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {list.isLoading && (
                  <tr><td colSpan={config.columns.length + 1} className="p-8 text-center text-slate-400">Carregando…</td></tr>
                )}
                {!list.isLoading && rows.length === 0 && (
                  <tr><td colSpan={config.columns.length + 1} className="p-8 text-center text-slate-500">Nenhum registro encontrado.</td></tr>
                )}
                {rows.map((row) => (
                  <tr key={row.id} className="hover:bg-slate-50">
                    {config.columns.map((c) => (
                      <td key={c.key} className={cx('px-4 py-3 text-slate-700', c.className)}>
                        {c.render ? c.render(row) : String(row[c.key] ?? '—')}
                      </td>
                    ))}
                    <td className="whitespace-nowrap px-4 py-2 text-right">
                      <div className="inline-flex items-center gap-1">
                        {config.rowActions?.(row, reload)}
                        {canWrite && (
                          <button type="button" onClick={() => openForm(row)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-primary" title="Editar">
                            <Pencil className="h-4 w-4" aria-label="Editar" />
                          </button>
                        )}
                        {canDelete && (
                          <button type="button" onClick={() => remove(row)} className="rounded-lg p-2 text-slate-500 hover:bg-red-50 hover:text-red-600" title="Excluir">
                            <Trash2 className="h-4 w-4" aria-label="Excluir" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {!blocked && total > 25 && (
          <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm text-slate-600">
            <span>{total} registros</span>
            <div className="flex items-center gap-2">
              <Button variant="secondary" className="px-2 py-1.5" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} aria-label="Página anterior"><ChevronLeft className="h-4 w-4" /></Button>
              <span>{page} / {pages}</span>
              <Button variant="secondary" className="px-2 py-1.5" disabled={page >= pages} onClick={() => setPage((p) => p + 1)} aria-label="Próxima página"><ChevronRight className="h-4 w-4" /></Button>
            </div>
          </div>
        )}
      </div>

      <Modal
        open={editing !== null}
        title={editing === 'new' ? `Novo(a) ${config.singular.toLowerCase()}` : `Editar ${config.singular.toLowerCase()}`}
        onClose={() => setEditing(null)}
        size={config.formSize ?? 'lg'}
        footer={<>
          <Button variant="secondary" onClick={() => setEditing(null)}>Cancelar</Button>
          <Button type="submit" form="crud-form" loading={save.isPending}>Salvar</Button>
        </>}
      >
        <form id="crud-form" onSubmit={submit} noValidate className="space-y-4">
          {formError && <Alert>{formError}</Alert>}
          <div className="grid gap-4 sm:grid-cols-2">
            {config.fields.filter((f) => !f.hidden?.(form)).map((f) => (
              <FieldInput key={f.name} f={f} value={form[f.name]} error={errors[f.name]} form={form}
                onChange={(v) => setForm((s) => ({ ...s, [f.name]: v }))} />
            ))}
          </div>
        </form>
      </Modal>
    </div>
  );
}

function FilterControl({ f, filters, onChange }: { f: FilterDef; filters: Record<string, string>; onChange: (v: string) => void }) {
  const params = f.listaParams ? f.listaParams(filters) : '';
  const lista = useLista(f.lista && params !== null ? f.lista : null, params ?? '');
  return (
    <FilterSelect label={f.label} placeholder={`${f.label}: todos`} value={filters[f.param] ?? ''} onChange={onChange}
      options={f.options ?? lista.data ?? []} />
  );
}
