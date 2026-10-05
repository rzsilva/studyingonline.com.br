import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRightLeft, Save, Search, Trash2, UserPlus } from 'lucide-react';
import { ApiError, http } from '../../api/client';
import { useAuth } from '../../auth/AuthProvider';
import { Alert, Button, Card, cx } from '../../components/ui';
import { Checkbox, FilterSelect, Select, useLista } from '../../components/form';
import { Modal, useFeedback } from '../../components/overlay';
import { fmt } from '../../components/CrudPage';
import { Perfil } from '../../lib/types';

interface Aluno { id: number; nome: string; email: string; matricula: string | null; inativo: boolean; dataMatricula: string | null }

/* ================= Montar turma ================= */

export function TurmasPage() {
  const { user } = useAuth();
  const isAdmin = user?.perfilId === Perfil.Administrador;
  const qc = useQueryClient();
  const { toast, confirm } = useFeedback();
  const cursos = useLista('cursos');
  const [cursoId, setCursoId] = useState('');
  const [adicionar, setAdicionar] = useState(false);
  const [trocar, setTrocar] = useState<Aluno | null>(null);

  const key = ['turma', cursoId];
  const alunos = useQuery({ queryKey: key, queryFn: () => http.get<Aluno[]>(`/cursos/${cursoId}/alunos`), enabled: !!cursoId });

  const remover = async (a: Aluno) => {
    if (!(await confirm(`Remover ${a.nome} desta turma?`, { danger: true }))) return;
    try {
      await http.del(`/cursos/${cursoId}/alunos/${a.id}`);
      toast('Aluno removido da turma.');
      qc.invalidateQueries({ queryKey: key });
    } catch (e) {
      toast(e instanceof ApiError ? e.message : 'Falha ao remover.', 'error');
    }
  };

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Montar turma</h1>
          <p className="mt-1 text-sm text-slate-500">Alunos matriculados em cada curso.</p>
        </div>
        {isAdmin && cursoId && <Button onClick={() => setAdicionar(true)}><UserPlus className="h-4 w-4" /> Adicionar alunos</Button>}
      </div>
      <FilterSelect label="Curso" placeholder="Selecione o curso" value={cursoId} onChange={setCursoId} options={cursos.data ?? []} />

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {!cursoId ? (
          <p className="p-8 text-center text-sm text-slate-500">Selecione um curso.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50">
                <tr>
                  {['Aluno', 'Matrícula', 'Matriculado em', 'Situação'].map((h) => <th key={h} className="px-4 py-3 text-left font-semibold text-slate-600">{h}</th>)}
                  <th className="px-4 py-3"><span className="sr-only">Ações</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {alunos.data?.length === 0 && <tr><td colSpan={5} className="p-8 text-center text-slate-500">Nenhum aluno nesta turma.</td></tr>}
                {alunos.data?.map((a) => (
                  <tr key={a.id} className="hover:bg-slate-50">
                    <td className="px-4 py-3"><p className="font-medium text-slate-900">{a.nome}</p><p className="text-xs text-slate-500">{a.email}</p></td>
                    <td className="px-4 py-3">{a.matricula ?? '—'}</td>
                    <td className="px-4 py-3">{fmt.date(a.dataMatricula)}</td>
                    <td className="px-4 py-3">{a.inativo ? <span className="text-amber-600">Inativo</span> : 'Ativo'}</td>
                    <td className="whitespace-nowrap px-4 py-2 text-right">
                      {isAdmin && <>
                        <button type="button" onClick={() => setTrocar(a)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-primary" title="Trocar de turma"><ArrowRightLeft className="h-4 w-4" /></button>
                        <button type="button" onClick={() => remover(a)} className="rounded-lg p-2 text-slate-500 hover:bg-red-50 hover:text-red-600" title="Remover"><Trash2 className="h-4 w-4" /></button>
                      </>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {adicionar && <AdicionarAlunosModal cursoId={cursoId} onClose={() => { setAdicionar(false); qc.invalidateQueries({ queryKey: key }); }} />}
      {trocar && <TrocarTurmaModal cursoId={cursoId} aluno={trocar} onClose={() => { setTrocar(null); qc.invalidateQueries({ queryKey: key }); }} />}
    </div>
  );
}

function AdicionarAlunosModal({ cursoId, onClose }: { cursoId: string; onClose: () => void }) {
  const { toast } = useFeedback();
  const [q, setQ] = useState('');
  const [busca, setBusca] = useState('');
  const [sel, setSel] = useState<number[]>([]);
  useEffect(() => { const t = setTimeout(() => setBusca(q), 350); return () => clearTimeout(t); }, [q]);
  const disp = useQuery({
    queryKey: ['disponiveis', cursoId, busca],
    queryFn: () => http.get<Aluno[]>(`/cursos/${cursoId}/alunos-disponiveis?q=${encodeURIComponent(busca)}`),
  });
  const salvar = useMutation({
    mutationFn: () => http.post<{ adicionados: number }>(`/cursos/${cursoId}/alunos`, { usuarioIds: sel }),
    onSuccess: (r) => { toast(`${r.adicionados} aluno(s) adicionado(s).`); onClose(); },
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.fields)[0] ?? e.message : 'Falha ao adicionar.', 'error'),
  });
  return (
    <Modal open title="Adicionar alunos à turma" onClose={onClose} size="lg"
      footer={<><Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button disabled={!sel.length} loading={salvar.isPending} onClick={() => salvar.mutate()}>Adicionar {sel.length || ''}</Button></>}>
      <div className="relative mb-3">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input type="search" aria-label="Buscar aluno" placeholder="Nome, e-mail ou matrícula" value={q} onChange={(e) => setQ(e.target.value)}
          className="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15" />
      </div>
      <div className="max-h-80 space-y-1 overflow-y-auto">
        {disp.data?.length === 0 && <p className="py-6 text-center text-sm text-slate-500">Nenhum aluno disponível.</p>}
        {disp.data?.map((a) => (
          <Checkbox key={a.id} className="rounded-lg px-2 py-2 hover:bg-slate-50" label={`${a.nome} — ${a.email}`} checked={sel.includes(a.id)}
            onChange={(e) => setSel((s) => (e.target.checked ? [...s, a.id] : s.filter((x) => x !== a.id)))} />
        ))}
      </div>
    </Modal>
  );
}

function TrocarTurmaModal({ cursoId, aluno, onClose }: { cursoId: string; aluno: Aluno; onClose: () => void }) {
  const { toast } = useFeedback();
  const cursos = useLista('cursos');
  const [destino, setDestino] = useState('');
  const trocar = useMutation({
    mutationFn: () => http.post(`/cursos/${cursoId}/alunos/${aluno.id}/trocar`, { cursoDestinoId: Number(destino) }),
    onSuccess: () => { toast('Aluno transferido.'); onClose(); },
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.fields)[0] ?? e.message : 'Falha ao transferir.', 'error'),
  });
  return (
    <Modal open title={`Trocar turma — ${aluno.nome}`} onClose={onClose}
      footer={<><Button variant="secondary" onClick={onClose}>Cancelar</Button><Button disabled={!destino} loading={trocar.isPending} onClick={() => trocar.mutate()}>Transferir</Button></>}>
      <Select label="Curso de destino" options={(cursos.data ?? []).filter((c) => String(c.id) !== cursoId)} value={destino} onChange={(e) => setDestino(e.target.value)} />
      <p className="mt-2 text-xs text-slate-500">A inscrição original é mantida. As notas do curso anterior não são transferidas.</p>
    </Modal>
  );
}

/* ================= Notas por curso / disciplina ================= */

interface NotaAluno { usuarioId: number; nome: string; matricula: string | null; nota1: number | null; notaRecuperacao: number | null; faltas: number | null; statusId: number }
interface NotasModulo { modulo: { id: number; nome: string; curso: string; media: number | null }; alunos: NotaAluno[] }

const STATUS = [{ id: 1, nome: 'Cursando' }, { id: 2, nome: 'Aprovado' }, { id: 3, nome: 'Reprovado' }];

export function NotasModuloPage() {
  const qc = useQueryClient();
  const { toast } = useFeedback();
  const cursos = useLista('cursos');
  const [cursoId, setCursoId] = useState('');
  const [moduloId, setModuloId] = useState('');
  const modulos = useLista(cursoId ? 'modulos' : null, `?cursoId=${cursoId}`);
  const [linhas, setLinhas] = useState<Record<number, Record<string, string>>>({});
  const [erro, setErro] = useState<string | null>(null);

  const dados = useQuery({
    queryKey: ['notas-modulo', moduloId],
    queryFn: () => http.get<NotasModulo>(`/notas/modulos/${moduloId}`),
    enabled: !!moduloId,
  });

  useEffect(() => {
    if (!dados.data) return;
    setLinhas(Object.fromEntries(dados.data.alunos.map((a) => [a.usuarioId, {
      nota1: a.nota1?.toString() ?? '', notaRecuperacao: a.notaRecuperacao?.toString() ?? '', faltas: a.faltas?.toString() ?? '', statusId: '',
    }])));
  }, [dados.data]);

  const salvar = useMutation({
    mutationFn: () => http.put<{ salvas: number }>(`/notas/modulos/${moduloId}`, {
      notas: Object.entries(linhas).map(([id, l]) => ({ usuarioId: Number(id), nota1: l.nota1, notaRecuperacao: l.notaRecuperacao, faltas: l.faltas, ...(l.statusId ? { statusId: Number(l.statusId) } : {}) })),
    }),
    onSuccess: (r) => { setErro(null); toast(`${r.salvas} nota(s) salva(s).`); qc.invalidateQueries({ queryKey: ['notas-modulo', moduloId] }); },
    onError: (e) => setErro(e instanceof ApiError ? Object.values(e.fields)[0] ?? e.message : 'Falha ao salvar.'),
  });

  const set = (id: number, k: string, v: string) => setLinhas((s) => ({ ...s, [id]: { ...s[id], [k]: v } }));
  const media = dados.data?.modulo.media;

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Lançar notas</h1>
        <p className="mt-1 text-sm text-slate-500">Escolha o curso e o módulo. A situação é calculada pela média do curso, a menos que você a defina manualmente.</p>
      </div>
      <div className="flex flex-wrap gap-2">
        <FilterSelect label="Curso" placeholder="Selecione o curso" value={cursoId} onChange={(v) => { setCursoId(v); setModuloId(''); }} options={cursos.data ?? []} />
        <FilterSelect label="Módulo" placeholder="Selecione o módulo" value={moduloId} onChange={setModuloId} options={modulos.data ?? []} />
      </div>
      {erro && <Alert>{erro}</Alert>}
      {moduloId && dados.data && (
        <Card>
          <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm text-slate-600"><strong>{dados.data.modulo.nome}</strong> · {dados.data.modulo.curso} · média {media ?? '—'}</p>
            <Button onClick={() => salvar.mutate()} loading={salvar.isPending} disabled={!dados.data.alunos.length}><Save className="h-4 w-4" /> Salvar notas</Button>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead><tr className="border-b border-slate-200 text-left text-slate-600">
                <th className="py-2 pr-4 font-semibold">Aluno</th><th className="px-2 py-2 font-semibold">Nota</th>
                <th className="px-2 py-2 font-semibold">Recuperação</th><th className="px-2 py-2 font-semibold">Faltas</th><th className="px-2 py-2 font-semibold">Situação</th>
              </tr></thead>
              <tbody className="divide-y divide-slate-100">
                {dados.data.alunos.length === 0 && <tr><td colSpan={5} className="py-6 text-center text-slate-500">Nenhum aluno matriculado.</td></tr>}
                {dados.data.alunos.map((a) => {
                  const l = linhas[a.usuarioId] ?? {};
                  const cell = 'w-24 rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20';
                  return (
                    <tr key={a.usuarioId}>
                      <td className="py-2 pr-4"><p className="font-medium text-slate-900">{a.nome}</p><p className="text-xs text-slate-500">{a.matricula}</p></td>
                      <td className="px-2"><input aria-label={`Nota de ${a.nome}`} inputMode="decimal" className={cell} value={l.nota1 ?? ''} onChange={(e) => set(a.usuarioId, 'nota1', e.target.value)} /></td>
                      <td className="px-2"><input aria-label={`Recuperação de ${a.nome}`} inputMode="decimal" className={cell} value={l.notaRecuperacao ?? ''} onChange={(e) => set(a.usuarioId, 'notaRecuperacao', e.target.value)} /></td>
                      <td className="px-2"><input aria-label={`Faltas de ${a.nome}`} inputMode="numeric" className={cx(cell, 'w-20')} value={l.faltas ?? ''} onChange={(e) => set(a.usuarioId, 'faltas', e.target.value)} /></td>
                      <td className="px-2">
                        <select aria-label={`Situação de ${a.nome}`} className={cx(cell, 'w-36')} value={l.statusId ?? ''} onChange={(e) => set(a.usuarioId, 'statusId', e.target.value)}>
                          <option value="">Automática ({STATUS.find((s) => s.id === a.statusId)?.nome})</option>
                          {STATUS.map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}
                        </select>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  );
}

/* ================= Boletim (notas por aluno) ================= */

interface Boletim {
  aluno: { nome: string; matricula: string | null };
  curso: { nome: string; media: number | null };
  disciplinas: { moduloId: number; periodo: string | null; disciplina: string; nota: number | null; notaRecuperacao: number | null; faltas: number | null; resultado: string }[];
}

export function BoletimView({ usuarioId, cursoId }: { usuarioId: number | string; cursoId: number | string }) {
  const b = useQuery({ queryKey: ['boletim', usuarioId, cursoId], queryFn: () => http.get<Boletim>(`/notas/alunos/${usuarioId}?cursoId=${cursoId}`) });
  if (b.isLoading) return <p className="text-sm text-slate-400">Carregando…</p>;
  if (b.isError) return <Alert>{(b.error as Error).message}</Alert>;
  if (!b.data) return null;
  const cor = (r: string) => (r === 'APROVADO' ? 'text-emerald-700' : r === 'REPROVADO' ? 'text-red-600' : 'text-slate-500');
  return (
    <Card>
      <p className="mb-3 text-sm text-slate-600"><strong>{b.data.aluno.nome}</strong> · {b.data.curso.nome} · média {b.data.curso.media ?? '—'}</p>
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead><tr className="border-b border-slate-200 text-left text-slate-600">
            {['Período', 'Disciplina', 'Nota', 'Recuperação', 'Faltas', 'Resultado'].map((h) => <th key={h} className="px-2 py-2 font-semibold">{h}</th>)}
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {b.data.disciplinas.map((d) => (
              <tr key={d.moduloId}>
                <td className="px-2 py-2">{d.periodo ?? '—'}</td><td className="px-2 py-2">{d.disciplina}</td>
                <td className="px-2 py-2">{fmt.num(d.nota)}</td><td className="px-2 py-2">{fmt.num(d.notaRecuperacao)}</td>
                <td className="px-2 py-2">{d.faltas ?? '—'}</td><td className={cx('px-2 py-2 font-medium', cor(d.resultado))}>{d.resultado}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  );
}

export function NotasAlunoPage() {
  const cursos = useLista('cursos');
  const alunos = useLista('alunos');
  const [cursoId, setCursoId] = useState('');
  const [alunoId, setAlunoId] = useState('');
  return (
    <div className="space-y-5">
      <h1 className="text-2xl font-semibold text-slate-900">Notas por aluno</h1>
      <div className="flex flex-wrap gap-2">
        <FilterSelect label="Aluno" placeholder="Selecione o aluno" value={alunoId} onChange={setAlunoId} options={alunos.data ?? []} />
        <FilterSelect label="Curso" placeholder="Selecione o curso" value={cursoId} onChange={setCursoId} options={cursos.data ?? []} />
      </div>
      {alunoId && cursoId ? <BoletimView usuarioId={alunoId} cursoId={cursoId} /> : <p className="text-sm text-slate-500">Selecione o aluno e o curso.</p>}
    </div>
  );
}
