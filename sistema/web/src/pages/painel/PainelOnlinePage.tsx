import { useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, BookOpen, CheckCircle2, ChevronRight, CircleDashed, FileText, GraduationCap, Lock, PlayCircle } from 'lucide-react';
import { ApiError, http } from '../../api/client';
import { useAuth } from '../../auth/AuthProvider';
import { Alert, Button, Card, FullPageSpinner, cx } from '../../components/ui';
import { Checkbox } from '../../components/form';
import { Modal, useFeedback } from '../../components/overlay';
import { fmt } from '../../components/CrudPage';
import { abrirMaterial } from '../academico/CadastroPages';
import { BoletimView } from '../academico/TurmaNotasPages';
import { Perfil } from '../../lib/types';
import { Anotacoes } from './Anotacoes';

/* ---------- tipos da API /painel ---------- */
interface CursoAluno { id: number; nome: string; subtitulo: string | null; foto: string | null; totalModulos: number; dataMatricula: string | null }
interface Video { id: number; titulo: string; descricao: string | null; url: string | null; youtube: boolean; vimeo: boolean; liberado: boolean; assistido: boolean }
interface Questao {
  id: number; enunciado: string; valor: number; opcoes: { letra: string; texto: string }[];
  resultado: { resposta: string | null; acertou: boolean; nota: number } | null;
  resultadoRecuperacao: { resposta: string | null; acertou: boolean; nota: number } | null;
}
interface Prova { id: number; disponivel: boolean; realizada: boolean; recuperacaoRealizada: boolean; valorTotal: number; questoes: Questao[] }
interface Modulo {
  id: number; nome: string; professor: string | null; liberado: boolean; concluido: boolean; conteudoConcluido: boolean;
  dataLiberacaoPrevista: string | null; videos: Video[]; arquivos: { id: number; titulo: string; url: string | null }[];
  prova: Prova | null; nota: { nota: number | null; notaRecuperacao: number | null; status: string } | null;
}
interface Trilha { curso: { id: number; nome: string; media: number | null }; modulos: Modulo[] }

/** Converte links do YouTube/Vimeo em URL de incorporação. */
export function embedUrl(v: Pick<Video, 'url'>): { kind: 'iframe' | 'video' | 'link'; src: string } | null {
  if (!v.url) return null;
  const u = v.url;
  const yt = /(?:youtu\.be\/|youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/))([\w-]{11})/.exec(u);
  if (yt) return { kind: 'iframe', src: `https://www.youtube.com/embed/${yt[1]}?rel=0` };
  const vm = /vimeo\.com\/(?:video\/)?(\d+)/.exec(u);
  if (vm) return { kind: 'iframe', src: `https://player.vimeo.com/video/${vm[1]}` };
  if (/\.(mp4|webm|ogg)(\?|$)/i.test(u)) return { kind: 'video', src: u };
  return { kind: 'link', src: u };
}

/* ================= Lista de cursos ================= */

export function PainelCursosPage() {
  const cursos = useQuery({ queryKey: ['painel-cursos'], queryFn: () => http.get<CursoAluno[]>('/painel/cursos') });
  if (cursos.isLoading) return <FullPageSpinner />;
  if (cursos.isError) return <Alert>{(cursos.error as Error).message}</Alert>;
  return (
    <div className="space-y-5">
      <h1 className="text-2xl font-semibold text-slate-900">Meus cursos</h1>
      {cursos.data?.length === 0 && <Card><p className="text-sm text-slate-500">Você ainda não está matriculado em nenhum curso.</p></Card>}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {cursos.data?.map((c) => (
          <Link key={c.id} to={`/painel/online/${c.id}`} className="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
            <div className="flex h-32 items-center justify-center bg-gradient-to-br from-primary to-primary/70">
              {c.foto ? <img src={c.foto} alt="" className="h-full w-full object-cover" /> : <GraduationCap className="h-12 w-12 text-white/80" />}
            </div>
            <div className="p-4">
              <p className="font-semibold text-slate-900 group-hover:text-primary">{c.nome}</p>
              {c.subtitulo && <p className="text-sm text-slate-500">{c.subtitulo}</p>}
              <p className="mt-2 flex items-center justify-between text-xs text-slate-500">
                <span>{c.totalModulos} módulo(s)</span>
                <ChevronRight className="h-4 w-4" />
              </p>
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}

/* ================= Trilha do curso ================= */

export function PainelTrilhaPage() {
  const { cursoId } = useParams();
  const { user } = useAuth();
  const key = ['trilha', cursoId];
  const trilha = useQuery({ queryKey: key, queryFn: () => http.get<Trilha>(`/painel/cursos/${cursoId}`) });
  const [moduloId, setModuloId] = useState<number | null>(null);
  const [aba, setAba] = useState<'aulas' | 'material' | 'prova' | 'boletim'>('aulas');

  const modulos = trilha.data?.modulos ?? [];
  useEffect(() => {
    if (moduloId === null && modulos.length) {
      // abre no primeiro módulo liberado e não concluído
      setModuloId((modulos.find((m) => m.liberado && !m.concluido) ?? modulos[0]).id);
    }
  }, [modulos, moduloId]);
  const modulo = modulos.find((m) => m.id === moduloId) ?? null;
  const progresso = modulos.length ? Math.round((modulos.filter((m) => m.concluido).length / modulos.length) * 100) : 0;

  if (trilha.isLoading) return <FullPageSpinner />;
  if (trilha.isError) return <Alert>{(trilha.error as Error).message}</Alert>;

  return (
    <div className="space-y-5">
      <div>
        <Link to="/painel/online" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-primary"><ArrowLeft className="h-4 w-4" /> Meus cursos</Link>
        <h1 className="mt-1 text-2xl font-semibold text-slate-900">{trilha.data?.curso.nome}</h1>
        <div className="mt-3 flex items-center gap-3">
          <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-200 sm:max-w-sm" role="progressbar" aria-valuenow={progresso} aria-valuemin={0} aria-valuemax={100}>
            <div className="h-full bg-primary transition-all" style={{ width: `${progresso}%` }} />
          </div>
          <span className="text-sm text-slate-600">{progresso}% concluído</span>
        </div>
      </div>

      <div className="grid gap-5 lg:grid-cols-[300px_1fr]">
        <nav aria-label="Módulos" className="space-y-1">
          {modulos.map((m, i) => (
            <button key={m.id} type="button" onClick={() => { setModuloId(m.id); setAba('aulas'); }}
              className={cx('flex w-full items-start gap-3 rounded-lg border px-3 py-3 text-left text-sm transition',
                m.id === moduloId ? 'border-primary bg-primary/5' : 'border-slate-200 bg-white hover:border-slate-300')}>
              <span className="mt-0.5">
                {m.concluido ? <CheckCircle2 className="h-5 w-5 text-emerald-600" aria-label="Concluído" />
                  : m.liberado ? <CircleDashed className="h-5 w-5 text-primary" aria-label="Em andamento" />
                    : <Lock className="h-5 w-5 text-slate-400" aria-label="Bloqueado" />}
              </span>
              <span className="flex-1">
                <span className="block text-xs text-slate-500">Módulo {i + 1}</span>
                <span className={cx('block font-medium', m.liberado ? 'text-slate-900' : 'text-slate-400')}>{m.nome}</span>
                {!m.liberado && m.dataLiberacaoPrevista && <span className="block text-xs text-slate-400">Previsto: {fmt.date(m.dataLiberacaoPrevista)}</span>}
              </span>
            </button>
          ))}
        </nav>

        <section>
          {modulo && !modulo.liberado ? (
            <Card className="text-center">
              <Lock className="mx-auto h-10 w-10 text-slate-300" />
              <p className="mt-3 font-medium text-slate-900">Módulo bloqueado</p>
              <p className="mt-1 text-sm text-slate-500">
                Conclua as aulas{modulos.some((m) => m.prova) ? ' e seja aprovado na prova' : ''} do módulo anterior para liberar este conteúdo.
                {modulo.dataLiberacaoPrevista && <> Liberação prevista a partir de <strong>{fmt.date(modulo.dataLiberacaoPrevista)}</strong>.</>}
              </p>
            </Card>
          ) : modulo ? (
            <div className="space-y-4">
              <div className="flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1" role="tablist">
                {([['aulas', 'Aulas'], ['material', `Material (${modulo.arquivos.length})`], ['prova', 'Prova'], ['boletim', 'Boletim']] as const).map(([k, l]) => (
                  (k !== 'prova' || modulo.prova) && (
                    <button key={k} role="tab" aria-selected={aba === k} type="button" onClick={() => setAba(k)}
                      className={cx('rounded-md px-3 py-1.5 text-sm font-medium', aba === k ? 'bg-white text-slate-900 shadow' : 'text-slate-600 hover:text-slate-900')}>{l}</button>
                  )
                ))}
              </div>
              {aba === 'aulas' && <AulasModulo modulo={modulo} cursoId={cursoId!} />}
              {aba === 'material' && (
                <Card title="Material de apoio">
                  {modulo.arquivos.length === 0 ? <p className="text-sm text-slate-500">Nenhum material para este módulo.</p> : (
                    <ul className="divide-y divide-slate-100">
                      {modulo.arquivos.map((a) => (
                        <li key={a.id}>
                          <button type="button" onClick={() => abrirMaterial(a.url, a.titulo)} className="flex w-full items-center gap-3 py-3 text-left text-sm hover:text-primary">
                            <FileText className="h-5 w-5 text-slate-400" /> {a.titulo}
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </Card>
              )}
              {aba === 'prova' && modulo.prova && <ProvaModulo modulo={modulo} prova={modulo.prova} cursoId={cursoId!} podeRecuperacao={user?.perfilId === Perfil.Administrador} />}
              {aba === 'boletim' && user && <BoletimView usuarioId={user.id} cursoId={cursoId!} />}
            </div>
          ) : (
            <Card><p className="text-sm text-slate-500">Este curso ainda não tem módulos.</p></Card>
          )}
        </section>
      </div>
    </div>
  );
}

function AulasModulo({ modulo, cursoId }: { modulo: Modulo; cursoId: string }) {
  const qc = useQueryClient();
  const { toast } = useFeedback();
  const [videoId, setVideoId] = useState<number | null>(null);
  const [confirmando, setConfirmando] = useState(false);
  const [aceite, setAceite] = useState(false);

  useEffect(() => {
    const atual = modulo.videos.find((v) => v.liberado && !v.assistido) ?? modulo.videos.find((v) => v.liberado) ?? null;
    setVideoId(atual?.id ?? null);
  }, [modulo.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const video = modulo.videos.find((v) => v.id === videoId) ?? null;
  const embed = useMemo(() => (video ? embedUrl(video) : null), [video]);

  const concluir = useMutation({
    mutationFn: () => http.post(`/painel/videos/${videoId}/progresso`, { evento: 'ended' }),
    onSuccess: async () => {
      setConfirmando(false);
      setAceite(false);
      toast('Aula concluída!');
      await qc.invalidateQueries({ queryKey: ['trilha', cursoId] });
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'Falha ao registrar.', 'error'),
  });

  if (modulo.videos.length === 0) return <Card><p className="text-sm text-slate-500">Nenhuma aula online neste módulo.</p></Card>;

  return (
    <div className="grid gap-4 xl:grid-cols-[1fr_280px]">
      <Card className="p-0">
        {video && embed ? (
          <>
            <div className="aspect-video w-full overflow-hidden rounded-t-xl bg-black">
              {embed.kind === 'iframe' && <iframe src={embed.src} title={video.titulo} className="h-full w-full" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowFullScreen referrerPolicy="strict-origin-when-cross-origin" />}
              {embed.kind === 'video' && <video src={embed.src} controls className="h-full w-full" onEnded={() => setConfirmando(true)} />}
              {embed.kind === 'link' && (
                <div className="flex h-full items-center justify-center">
                  <a href={embed.src} target="_blank" rel="noopener noreferrer" className="text-white underline">Abrir aula em nova aba</a>
                </div>
              )}
            </div>
            <div className="flex flex-wrap items-start justify-between gap-3 p-5">
              <div>
                <h2 className="font-semibold text-slate-900">{video.titulo}</h2>
                {video.descricao && <p className="mt-1 whitespace-pre-line text-sm text-slate-600">{video.descricao}</p>}
              </div>
              {video.assistido ? (
                <span className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700"><CheckCircle2 className="h-4 w-4" /> Aula concluída</span>
              ) : (
                <Button onClick={() => setConfirmando(true)}>Concluí esta aula</Button>
              )}
            </div>
          </>
        ) : (
          <p className="p-5 text-sm text-slate-500">Selecione uma aula liberada.</p>
        )}
      </Card>

      <div className="space-y-4">
      <Card title="Aulas do módulo">
        <ol className="space-y-1">
          {modulo.videos.map((v, i) => (
            <li key={v.id}>
              <button type="button" disabled={!v.liberado} onClick={() => setVideoId(v.id)}
                className={cx('flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm',
                  v.id === videoId ? 'bg-primary/10 text-primary' : 'hover:bg-slate-50', !v.liberado && 'cursor-not-allowed text-slate-400')}>
                {v.assistido ? <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" /> : v.liberado ? <PlayCircle className="h-4 w-4 shrink-0" /> : <Lock className="h-4 w-4 shrink-0" />}
                <span className="flex-1">{i + 1}. {v.titulo}</span>
              </button>
            </li>
          ))}
        </ol>
        {!modulo.conteudoConcluido && <p className="mt-3 text-xs text-slate-500">Cada aula é liberada após concluir a anterior.</p>}
      </Card>
      <Anotacoes moduloId={modulo.id} videoTitulo={video?.titulo} />
      </div>

      <Modal open={confirmando} title="Confirmação de aula assistida" onClose={() => setConfirmando(false)}
        footer={<>
          <Button variant="secondary" onClick={() => setConfirmando(false)}>Ainda não terminei</Button>
          <Button disabled={!aceite} loading={concluir.isPending} onClick={() => concluir.mutate()}>Confirmar</Button>
        </>}>
        <p className="mb-3 text-sm text-slate-600">Confirma que assistiu a todo o conteúdo desta aula?</p>
        <Checkbox label="Confirmo que assisti todo o conteúdo desta aula." checked={aceite} onChange={(e) => setAceite(e.target.checked)} />
      </Modal>
    </div>
  );
}

function ProvaModulo({ modulo, prova, cursoId, podeRecuperacao }: { modulo: Modulo; prova: Prova; cursoId: string; podeRecuperacao: boolean }) {
  const qc = useQueryClient();
  const { toast, confirm } = useFeedback();
  const [recuperacao, setRecuperacao] = useState(false);
  const [respostas, setRespostas] = useState<Record<number, string>>({});
  const feita = recuperacao ? prova.recuperacaoRealizada : prova.realizada;

  const enviar = useMutation({
    mutationFn: () => http.post<{ nota: number; status: string }>(`/painel/provas/${prova.id}/respostas`, { respostas, recuperacao }),
    onSuccess: async (r) => {
      toast(`Prova enviada: nota ${r.nota.toLocaleString('pt-BR')} — ${r.status}.`, r.status === 'APROVADO' ? 'success' : 'error');
      setRespostas({});
      await qc.invalidateQueries({ queryKey: ['trilha', cursoId] });
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'Falha ao enviar.', 'error'),
  });

  const submit = async () => {
    const faltam = prova.questoes.length - Object.keys(respostas).length;
    const msg = faltam > 0 ? `Há ${faltam} questão(ões) sem resposta, que valerão zero. Finalizar mesmo assim?` : 'Finalizar e enviar a prova? Não será possível refazê-la.';
    if (await confirm(msg)) enviar.mutate();
  };

  if (!prova.disponivel && !prova.realizada) {
    return (
      <Card className="text-center">
        <BookOpen className="mx-auto h-10 w-10 text-slate-300" />
        <p className="mt-3 font-medium text-slate-900">Prova ainda indisponível</p>
        <p className="mt-1 text-sm text-slate-500">Conclua todas as aulas do módulo para liberar a prova.</p>
      </Card>
    );
  }

  return (
    <Card>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="font-semibold text-slate-900">{recuperacao ? 'Prova de recuperação' : 'Prova'} — {modulo.nome}</h2>
          <p className="text-sm text-slate-500">{prova.questoes.length} questões · valor total {prova.valorTotal.toLocaleString('pt-BR')}</p>
        </div>
        {modulo.nota && modulo.nota.status !== 'CURSANDO' && (
          <div className="text-right">
            <p className="text-sm text-slate-500">Nota {fmt.num(modulo.nota.nota)}{modulo.nota.notaRecuperacao != null && ` · Recuperação ${fmt.num(modulo.nota.notaRecuperacao)}`}</p>
            <p className={cx('font-semibold', modulo.nota.status === 'APROVADO' ? 'text-emerald-700' : 'text-red-600')}>{modulo.nota.status}</p>
          </div>
        )}
      </div>
      {podeRecuperacao && prova.realizada && (
        <Checkbox className="mb-4" label="Prova de recuperação" checked={recuperacao} onChange={(e) => setRecuperacao(e.target.checked)} />
      )}

      <ol className="space-y-5">
        {prova.questoes.map((q, i) => {
          const res = recuperacao ? q.resultadoRecuperacao : q.resultado;
          return (
            <li key={q.id} className="rounded-lg border border-slate-200 p-4">
              <fieldset disabled={feita}>
                <legend className="font-medium text-slate-900">{i + 1}. {q.enunciado} <span className="text-xs font-normal text-slate-500">({q.valor.toLocaleString('pt-BR')} pts)</span></legend>
                <div className="mt-3 space-y-2">
                  {q.opcoes.map((o) => {
                    const marcada = feita ? res?.resposta === o.letra : respostas[q.id] === o.letra;
                    return (
                      <label key={o.letra} className={cx('flex cursor-pointer items-start gap-2 rounded-md px-2 py-1.5 text-sm',
                        marcada ? (feita ? (res?.acertou ? 'bg-emerald-50' : 'bg-red-50') : 'bg-primary/5') : 'hover:bg-slate-50')}>
                        <input type="radio" name={`q${q.id}`} value={o.letra} checked={marcada}
                          onChange={() => setRespostas((r) => ({ ...r, [q.id]: o.letra }))} className="mt-0.5 text-primary focus:ring-primary/30" />
                        <span><strong>{o.letra})</strong> {o.texto}</span>
                      </label>
                    );
                  })}
                </div>
                {feita && res && (
                  <p className={cx('mt-2 text-sm font-medium', res.acertou ? 'text-emerald-700' : 'text-red-600')}>
                    {res.acertou ? `Você acertou (+${res.nota.toLocaleString('pt-BR')})` : 'Resposta incorreta'}
                  </p>
                )}
              </fieldset>
            </li>
          );
        })}
      </ol>
      {!feita && (
        <div className="mt-5 flex justify-end">
          <Button onClick={submit} loading={enviar.isPending} disabled={!Object.keys(respostas).length}>Finalizar prova</Button>
        </div>
      )}
    </Card>
  );
}
