import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, CalendarDays, ChevronRight, Clock, FileText, School, Users } from 'lucide-react';
import { ApiError, http } from '../../api/client';
import { useFeedback } from '../../components/overlay';
import { useAuth } from '../../auth/AuthProvider';
import { Alert, Button, Card, FullPageSpinner, cx } from '../../components/ui';
import { fmt } from '../../components/CrudPage';
import { abrirMaterial } from '../academico/CadastroPages';
import { BoletimView } from '../academico/TurmaNotasPages';
import { Avatar } from './ForumPages';

interface CursoP { id: number; nome: string; subtitulo: string | null; foto: string | null }
interface Aula { id: number; titulo: string; descricao: string | null; url: string | null; data: string | null; inicio: string | null; termino: string | null }
interface ModuloP { id: number; nome: string; periodo: string | null; professor: string | null; aulas: Aula[]; arquivos: { id: number; titulo: string; url: string | null }[] }
interface CursoDetalhe { curso: { id: number; nome: string }; modulos: ModuloP[]; colegas: { id: number; nome: string; foto: string | null }[] }

export function PresencialCursosPage() {
  const cursos = useQuery({ queryKey: ['presencial-cursos'], queryFn: () => http.get<CursoP[]>('/painel-presencial/cursos') });
  if (cursos.isLoading) return <FullPageSpinner />;
  if (cursos.isError) return <Alert>{(cursos.error as Error).message}</Alert>;
  return (
    <div className="space-y-5">
      <h1 className="text-2xl font-semibold text-slate-900">Painel presencial</h1>
      {cursos.data?.length === 0 && <Card><p className="text-sm text-slate-500">Você não está em nenhuma turma presencial.</p></Card>}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {cursos.data?.map((c) => (
          <Link key={c.id} to={`/painel/presencial/${c.id}`} className="group flex items-center gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:shadow-md">
            <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary"><School className="h-6 w-6" /></span>
            <span className="flex-1">
              <span className="block font-semibold text-slate-900 group-hover:text-primary">{c.nome}</span>
              {c.subtitulo && <span className="block text-sm text-slate-500">{c.subtitulo}</span>}
            </span>
            <ChevronRight className="h-4 w-4 text-slate-400" />
          </Link>
        ))}
      </div>
    </div>
  );
}

export function PresencialCursoPage() {
  const { cursoId } = useParams();
  const { user } = useAuth();
  const [aba, setAba] = useState<'aulas' | 'boletim' | 'colegas'>('aulas');
  const d = useQuery({ queryKey: ['presencial', cursoId], queryFn: () => http.get<CursoDetalhe>(`/painel-presencial/cursos/${cursoId}`) });
  if (d.isLoading) return <FullPageSpinner />;
  if (d.isError || !d.data) return <Alert>{(d.error as Error)?.message}</Alert>;
  const hoje = new Date().toISOString().slice(0, 10);
  const proximas = d.data.modulos.flatMap((m) => m.aulas.map((a) => ({ ...a, modulo: m.nome })))
    .filter((a) => a.data && a.data.slice(0, 10) >= hoje)
    .sort((a, b) => String(a.data).localeCompare(String(b.data)))
    .slice(0, 3);

  return (
    <div className="space-y-5">
      <div>
        <Link to="/painel/presencial" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-primary"><ArrowLeft className="h-4 w-4" /> Turmas presenciais</Link>
        <h1 className="mt-1 text-2xl font-semibold text-slate-900">{d.data.curso.nome}</h1>
      </div>

      {proximas.length > 0 && (
        <div className="grid gap-3 sm:grid-cols-3">
          {proximas.map((a) => (
            <Card key={a.id} className="border-primary/30 bg-primary/5">
              <p className="text-xs font-semibold uppercase tracking-wide text-primary">Próxima aula</p>
              <p className="mt-1 font-medium text-slate-900">{a.titulo}</p>
              <p className="text-sm text-slate-600">{fmt.date(a.data)} · {fmt.time(a.inicio)}–{fmt.time(a.termino)}</p>
              <p className="text-xs text-slate-500">{a.modulo}</p>
            </Card>
          ))}
        </div>
      )}

      <div className="flex gap-1 rounded-lg bg-slate-100 p-1" role="tablist">
        {([['aulas', 'Aulas e material'], ['boletim', 'Boletim escolar'], ['colegas', `Turma (${d.data.colegas.length})`]] as const).map(([k, l]) => (
          <button key={k} role="tab" type="button" aria-selected={aba === k} onClick={() => setAba(k)}
            className={cx('rounded-md px-3 py-1.5 text-sm font-medium', aba === k ? 'bg-white text-slate-900 shadow' : 'text-slate-600 hover:text-slate-900')}>{l}</button>
        ))}
      </div>

      {aba === 'aulas' && (
        <div className="space-y-4">
          {d.data.modulos.map((m) => (
            <Card key={m.id}>
              <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="font-semibold text-slate-900">{m.nome}</h2>
                <p className="text-xs text-slate-500">{[m.periodo, m.professor].filter(Boolean).join(' · ')}</p>
              </div>
              {m.aulas.length === 0 ? <p className="text-sm text-slate-500">Nenhuma aula agendada.</p> : (
                <ul className="divide-y divide-slate-100">
                  {m.aulas.map((a) => (
                    <li key={a.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-2 text-sm">
                      <span className="flex items-center gap-1 text-slate-600"><CalendarDays className="h-4 w-4" />{fmt.date(a.data)}</span>
                      <span className="flex items-center gap-1 text-slate-500"><Clock className="h-4 w-4" />{fmt.time(a.inicio)}–{fmt.time(a.termino)}</span>
                      <span className="flex-1 font-medium text-slate-800">{a.titulo}</span>
                      {a.url && <a href={a.url} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">Gravação</a>}
                    </li>
                  ))}
                </ul>
              )}
              {m.arquivos.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                  {m.arquivos.map((a) => (
                    <button key={a.id} type="button" onClick={() => abrirMaterial(a.url, a.titulo)}
                      className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-700 hover:border-primary hover:text-primary">
                      <FileText className="h-3.5 w-3.5" />{a.titulo}
                    </button>
                  ))}
                </div>
              )}
            </Card>
          ))}
        </div>
      )}
      {aba === 'boletim' && user && <BoletimView usuarioId={user.id} cursoId={cursoId!} />}
      {aba === 'colegas' && (
        <Card title="Colegas de turma">
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {d.data.colegas.map((c) => (
              <li key={c.id} className="flex items-center gap-3 text-sm text-slate-700"><Avatar nome={c.nome} foto={c.foto} size="sm" />{c.nome}</li>
            ))}
          </ul>
          {d.data.colegas.length === 0 && <p className="flex items-center gap-2 text-sm text-slate-500"><Users className="h-4 w-4" />Nenhum colega na turma.</p>}
        </Card>
      )}
    </div>
  );
}

/* ---------- Extrato financeiro do aluno ---------- */

interface Titulo { id: number; vencimento: string | null; pagamento: string | null; valor: number; documento: string | null; descricao: string | null; situacao: string; pago: boolean; cancelado?: boolean; vencido: boolean }

export function MeuFinanceiroPage() {
  const q = useQuery({ queryKey: ['meu-financeiro'], queryFn: () => http.get<Titulo[]>('/me/financeiro') });
  const formas = useQuery({ queryKey: ['formas-pagamento'], queryFn: () => http.get<string[]>('/me/formas-pagamento') });
  if (q.isLoading) return <FullPageSpinner />;
  if (q.isError) return <Alert>{(q.error as Error).message}</Alert>;
  const abertos = (q.data ?? []).filter((t) => !t.pago && !t.cancelado);
  const vencidos = abertos.filter((t) => t.vencido);
  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Financeiro</h1>
        <p className="mt-1 text-sm text-slate-500">Mensalidades e cobranças. Use "Pagar" para gerar o boleto/link ou a 2ª via; a baixa é automática após o pagamento.</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-3">
        <Card><p className="text-sm text-slate-500">Em aberto</p><p className="text-2xl font-semibold text-slate-900">{fmt.money(abertos.reduce((s, t) => s + t.valor, 0))}</p></Card>
        <Card className={vencidos.length ? 'border-red-200 bg-red-50' : ''}>
          <p className="text-sm text-slate-500">Vencidos</p>
          <p className={cx('text-2xl font-semibold', vencidos.length ? 'text-red-600' : 'text-slate-900')}>{vencidos.length}</p>
        </Card>
        <Card><p className="text-sm text-slate-500">Títulos pagos</p><p className="text-2xl font-semibold text-emerald-700">{(q.data ?? []).filter((t) => t.pago).length}</p></Card>
      </div>
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50"><tr>
            {['Vencimento', 'Descrição', 'Valor', 'Pagamento', 'Situação', ''].map((h) => <th key={h} className="px-4 py-3 text-left font-semibold text-slate-600">{h}</th>)}
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {q.data?.length === 0 && <tr><td colSpan={6} className="p-8 text-center text-slate-500">Nenhum lançamento.</td></tr>}
            {q.data?.map((t) => (
              <tr key={t.id}>
                <td className="px-4 py-3">{fmt.date(t.vencimento)}</td>
                <td className="px-4 py-3">{t.descricao ?? '—'}{t.documento && <span className="block text-xs text-slate-400">Doc. {t.documento}</span>}</td>
                <td className="px-4 py-3">{fmt.money(t.valor)}</td>
                <td className="px-4 py-3">{fmt.date(t.pagamento)}</td>
                <td className="px-4 py-3">
                  <span className={cx('rounded-full px-2 py-0.5 text-xs font-medium',
                    t.pago ? 'bg-emerald-50 text-emerald-700' : t.cancelado ? 'bg-slate-100 text-slate-500' : t.vencido ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700')}>
                    {t.pago ? 'Pago' : t.cancelado ? 'Cancelado' : t.vencido ? 'Vencido' : 'Em aberto'}
                  </span>
                </td>
                <td className="px-4 py-2 text-right">{!t.pago && !t.cancelado && <PagarTitulo titulo={t} formas={formas.data ?? []} />}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/** Gera o boleto/link (ou 2ª via) do título e abre em nova aba. */
function PagarTitulo({ titulo, formas }: { titulo: Titulo; formas: string[] }) {
  const { toast } = useFeedback();
  const [carregando, setCarregando] = useState<string | null>(null);
  if (formas.length === 0) return <span className="text-xs text-slate-400">Pague na secretaria</span>;
  const pagar = async (forma: string) => {
    setCarregando(forma);
    // abre a aba já no clique (bloqueadores de pop-up barram janelas abertas depois de uma espera)
    const aba = window.open('about:blank', '_blank');
    try {
      const r = await http.post<{ url: string }>(`/contas-receber/${titulo.id}/pagar`, { forma });
      if (aba) aba.location.href = r.url; else window.location.href = r.url;
    } catch (e) {
      aba?.close();
      toast(e instanceof ApiError ? e.message : 'Não foi possível gerar a cobrança.', 'error');
    } finally {
      setCarregando(null);
    }
  };
  return (
    <div className="inline-flex gap-1">
      {formas.map((f) => (
        <Button key={f} variant={f === 'boleto' ? 'primary' : 'secondary'} className="px-3 py-1.5 text-xs" loading={carregando === f} onClick={() => pagar(f)}>
          {f === 'boleto' ? 'Pagar' : 'Cartão'}
        </Button>
      ))}
    </div>
  );
}
