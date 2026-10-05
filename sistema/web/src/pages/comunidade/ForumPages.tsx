import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Eye, MessageSquare, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { ApiError, apiWithMeta, http } from '../../api/client';
import { Alert, Button, Card, Input, cx } from '../../components/ui';
import { FilterSelect, Select, Textarea, useLista } from '../../components/form';
import { Modal, useFeedback } from '../../components/overlay';

interface Topico {
  id: number; titulo: string; descricao: string; disciplinaId: number; disciplina: string | null; autor: string | null;
  autorFoto: string | null; data: string; editado: boolean; visualizacoes: number; respostas: number; ultimaResposta: string | null; podeEditar: boolean;
}
interface Resposta { id: number; texto: string; autor: string | null; autorFoto: string | null; autorPerfil: string | null; data: string; editado: boolean; podeEditar: boolean }

export const quando = (d: string | null) => {
  if (!d) return '';
  const data = new Date(d.replace(' ', 'T'));
  const diff = (Date.now() - data.getTime()) / 1000;
  if (diff < 60) return 'agora';
  if (diff < 3600) return `há ${Math.floor(diff / 60)} min`;
  if (diff < 86400) return `há ${Math.floor(diff / 3600)} h`;
  return data.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' });
};

export function Avatar({ nome, foto, size = 'md' }: { nome: string | null; foto?: string | null; size?: 'sm' | 'md' }) {
  const ini = (nome ?? '?').split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
  const cls = size === 'sm' ? 'h-8 w-8 text-xs' : 'h-10 w-10 text-sm';
  return foto && /^https?:\/\//.test(foto)
    ? <img src={foto} alt="" className={cx(cls, 'shrink-0 rounded-full object-cover')} />
    : <span className={cx(cls, 'flex shrink-0 items-center justify-center rounded-full bg-primary/15 font-semibold text-primary')}>{ini}</span>;
}

function TopicoForm({ inicial, onClose }: { inicial?: Topico; onClose: (id?: number) => void }) {
  const disciplinas = useLista('disciplinas-forum');
  const [form, setForm] = useState({ disciplinaId: String(inicial?.disciplinaId ?? ''), titulo: inicial?.titulo ?? '', descricao: inicial?.descricao ?? '' });
  const [erros, setErros] = useState<Record<string, string>>({});
  const salvar = useMutation({
    mutationFn: () => (inicial
      ? http.put(`/forum/topicos/${inicial.id}`, form).then(() => ({ id: inicial.id }))
      : http.post<{ id: number }>('/forum/topicos', { ...form, disciplinaId: Number(form.disciplinaId) })),
    onSuccess: (r) => onClose(r.id),
    onError: (e) => setErros(e instanceof ApiError ? (Object.keys(e.fields).length ? e.fields : { _: e.message }) : { _: 'Falha ao salvar.' }),
  });
  return (
    <Modal open title={inicial ? 'Editar tópico' : 'Novo tópico'} onClose={() => onClose()} size="lg"
      footer={<><Button variant="secondary" onClick={() => onClose()}>Cancelar</Button><Button loading={salvar.isPending} onClick={() => salvar.mutate()}>Publicar</Button></>}>
      <div className="space-y-4">
        {erros._ && <Alert>{erros._}</Alert>}
        {!inicial && <Select label="Disciplina *" options={disciplinas.data ?? []} value={form.disciplinaId} error={erros.disciplinaId}
          onChange={(e) => setForm((f) => ({ ...f, disciplinaId: e.target.value }))} />}
        <Input label="Título *" value={form.titulo} error={erros.titulo} maxLength={200} onChange={(e) => setForm((f) => ({ ...f, titulo: e.target.value }))} />
        <Textarea label="Mensagem *" rows={6} value={form.descricao} error={erros.descricao} onChange={(e) => setForm((f) => ({ ...f, descricao: e.target.value }))} />
      </div>
    </Modal>
  );
}

export function ForumPage() {
  const navigate = useNavigate();
  const disciplinas = useLista('disciplinas-forum');
  const [disciplinaId, setDisciplinaId] = useState('');
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [novo, setNovo] = useState(false);
  const qs = new URLSearchParams({ page: String(page), ...(disciplinaId ? { disciplinaId } : {}), ...(q ? { q } : {}) }).toString();
  const lista = useQuery({
    queryKey: ['forum', qs],
    queryFn: () => apiWithMeta<Topico[]>(`/forum/topicos?${qs}`),
    placeholderData: keepPreviousData,
  });
  const total = lista.data?.meta?.total ?? 0;

  if (lista.isError) return <Alert>{(lista.error as Error).message}</Alert>;
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Fórum</h1>
          <p className="mt-1 text-sm text-slate-500">Tire dúvidas e converse com colegas e professores.</p>
        </div>
        <Button onClick={() => setNovo(true)}><Plus className="h-4 w-4" /> Novo tópico</Button>
      </div>
      <div className="flex flex-wrap gap-2">
        <div className="relative min-w-[220px] flex-1 sm:max-w-xs">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input type="search" aria-label="Buscar tópicos" placeholder="Buscar…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }}
            className="w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15" />
        </div>
        <FilterSelect label="Disciplina" placeholder="Todas as disciplinas" value={disciplinaId} onChange={(v) => { setDisciplinaId(v); setPage(1); }} options={disciplinas.data ?? []} />
      </div>

      <div className="space-y-2">
        {lista.data?.data.length === 0 && <Card><p className="text-center text-sm text-slate-500">Nenhum tópico ainda. Que tal começar a conversa?</p></Card>}
        {lista.data?.data.map((t) => (
          <Link key={t.id} to={`/forum/${t.id}`} className="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-primary/40">
            <Avatar nome={t.autor} foto={t.autorFoto} />
            <div className="min-w-0 flex-1">
              <p className="truncate font-medium text-slate-900">{t.titulo}</p>
              <p className="mt-0.5 line-clamp-1 text-sm text-slate-500">{t.descricao}</p>
              <p className="mt-1 text-xs text-slate-400">
                {t.autor} · {t.disciplina} · {quando(t.ultimaResposta ?? t.data)}
              </p>
            </div>
            <div className="hidden shrink-0 gap-4 text-xs text-slate-500 sm:flex">
              <span className="flex items-center gap-1" title="Respostas"><MessageSquare className="h-4 w-4" />{t.respostas}</span>
              <span className="flex items-center gap-1" title="Visualizações"><Eye className="h-4 w-4" />{t.visualizacoes}</span>
            </div>
          </Link>
        ))}
      </div>
      {total > 20 && (
        <div className="flex justify-center gap-2">
          <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Anteriores</Button>
          <Button variant="secondary" disabled={page * 20 >= total} onClick={() => setPage((p) => p + 1)}>Próximos</Button>
        </div>
      )}
      {novo && <TopicoForm onClose={(id) => { setNovo(false); if (id) navigate(`/forum/${id}`); }} />}
    </div>
  );
}

export function TopicoPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { toast, confirm } = useFeedback();
  const key = ['topico', id];
  const topico = useQuery({ queryKey: key, queryFn: () => http.get<Topico & { respostas: Resposta[] }>(`/forum/topicos/${id}`) });
  const [texto, setTexto] = useState('');
  const [editTopico, setEditTopico] = useState(false);
  const [editResp, setEditResp] = useState<Resposta | null>(null);
  const [editTexto, setEditTexto] = useState('');

  const recarregar = () => qc.invalidateQueries({ queryKey: key });
  const erro = (e: unknown) => toast(e instanceof ApiError ? Object.values(e.fields)[0] ?? e.message : 'Falha na operação.', 'error');

  const responder = useMutation({
    mutationFn: () => http.post(`/forum/topicos/${id}/respostas`, { texto }),
    onSuccess: () => { setTexto(''); recarregar(); },
    onError: erro,
  });
  const salvarResp = useMutation({
    mutationFn: () => http.put(`/forum/respostas/${editResp!.id}`, { texto: editTexto }),
    onSuccess: () => { setEditResp(null); recarregar(); },
    onError: erro,
  });

  const excluirTopico = async () => {
    if (!(await confirm('Excluir este tópico e todas as respostas?', { danger: true }))) return;
    try { await http.del(`/forum/topicos/${id}`); toast('Tópico excluído.'); navigate('/forum'); } catch (e) { erro(e); }
  };
  const excluirResp = async (r: Resposta) => {
    if (!(await confirm('Excluir esta resposta?', { danger: true }))) return;
    try { await http.del(`/forum/respostas/${r.id}`); recarregar(); } catch (e) { erro(e); }
  };

  if (topico.isLoading) return <p className="text-sm text-slate-400">Carregando…</p>;
  if (topico.isError || !topico.data) return <Alert>{(topico.error as Error)?.message ?? 'Tópico não encontrado.'}</Alert>;
  const t = topico.data;

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <Link to="/forum" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-primary"><ArrowLeft className="h-4 w-4" /> Fórum</Link>
      <Card>
        <div className="flex items-start gap-3">
          <Avatar nome={t.autor} foto={t.autorFoto} />
          <div className="min-w-0 flex-1">
            <h1 className="text-xl font-semibold text-slate-900">{t.titulo}</h1>
            <p className="text-xs text-slate-500">{t.autor} · {t.disciplina} · {quando(t.data)}{t.editado && ' · editado'} · {t.visualizacoes} visualizações</p>
          </div>
          {t.podeEditar && (
            <div className="flex shrink-0 gap-1">
              <button type="button" onClick={() => setEditTopico(true)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Editar"><Pencil className="h-4 w-4" /></button>
              <button type="button" onClick={excluirTopico} className="rounded-lg p-2 text-slate-500 hover:bg-red-50 hover:text-red-600" title="Excluir"><Trash2 className="h-4 w-4" /></button>
            </div>
          )}
        </div>
        <p className="mt-4 whitespace-pre-line text-sm leading-relaxed text-slate-700">{t.descricao}</p>
      </Card>

      <h2 className="pt-2 text-sm font-semibold text-slate-600">{t.respostas.length} resposta(s)</h2>
      <ul className="space-y-3">
        {t.respostas.map((r) => (
          <li key={r.id}>
            <Card>
              <div className="flex items-start gap-3">
                <Avatar nome={r.autor} foto={r.autorFoto} size="sm" />
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-slate-900">
                    {r.autor}
                    {r.autorPerfil && r.autorPerfil !== 'ALUNO' && <span className="ml-2 rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-primary">{r.autorPerfil}</span>}
                  </p>
                  <p className="text-xs text-slate-400">{quando(r.data)}{r.editado && ' · editado'}</p>
                  {editResp?.id === r.id ? (
                    <div className="mt-2 space-y-2">
                      <Textarea label="Editar resposta" value={editTexto} onChange={(e) => setEditTexto(e.target.value)} />
                      <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setEditResp(null)}>Cancelar</Button>
                        <Button loading={salvarResp.isPending} onClick={() => salvarResp.mutate()}>Salvar</Button>
                      </div>
                    </div>
                  ) : (
                    <p className="mt-2 whitespace-pre-line text-sm text-slate-700">{r.texto}</p>
                  )}
                </div>
                {r.podeEditar && editResp?.id !== r.id && (
                  <div className="flex shrink-0 gap-1">
                    <button type="button" onClick={() => { setEditResp(r); setEditTexto(r.texto); }} className="rounded p-1.5 text-slate-400 hover:bg-slate-100" title="Editar"><Pencil className="h-4 w-4" /></button>
                    <button type="button" onClick={() => excluirResp(r)} className="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" title="Excluir"><Trash2 className="h-4 w-4" /></button>
                  </div>
                )}
              </div>
            </Card>
          </li>
        ))}
      </ul>

      <Card>
        <form onSubmit={(e) => { e.preventDefault(); if (texto.trim()) responder.mutate(); }} className="space-y-3">
          <Textarea label="Sua resposta" rows={4} value={texto} onChange={(e) => setTexto(e.target.value)} />
          <div className="flex justify-end"><Button type="submit" loading={responder.isPending} disabled={!texto.trim()}>Responder</Button></div>
        </form>
      </Card>
      {editTopico && <TopicoForm inicial={t} onClose={() => { setEditTopico(false); recarregar(); }} />}
    </div>
  );
}
