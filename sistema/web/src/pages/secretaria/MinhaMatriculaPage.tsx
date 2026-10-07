import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarClock, GraduationCap, RotateCw } from 'lucide-react';
import { ApiError, http } from '../../api/client';
import { Alert, Button, Card, FullPageSpinner, cx } from '../../components/ui';
import { useFeedback } from '../../components/overlay';
import { fmt } from '../../components/CrudPage';
import { DocumentosUpload } from './DocumentosUpload';
import { STATUS_INSCRICAO } from './SecretariaPages';
import type { CursoAberto } from './InscricaoPublicaPage';

interface MinhaInscricao {
  id: number; status: number; justificativaReprovacao: string | null; data: string | null;
  cursos: { id: number; nome: string }[];
  documentos: { campo: string; rotulo: string; enviado: boolean }[];
}
interface Rematricula { cursoId: number; curso: string; prazo: string; valor: number; realizada: boolean }

/** Área do aluno: inscrição, documentos, rematrícula e novos cursos (antes espalhado no legado). */
export function MinhaMatriculaPage() {
  const qc = useQueryClient();
  const { toast, confirm } = useFeedback();
  const insc = useQuery({ queryKey: ['minha-inscricao'], queryFn: () => http.get<MinhaInscricao | null>('/me/inscricao') });
  const rem = useQuery({ queryKey: ['minha-rematricula'], queryFn: () => http.get<Rematricula[]>('/me/rematricula') });
  const abertos = useQuery({ queryKey: ['me-cursos-abertos'], queryFn: () => http.get<CursoAberto[]>('/me/cursos-abertos') });
  const meus = new Set((insc.data?.cursos ?? []).map((c) => c.id));

  const erro = (e: unknown) => toast(e instanceof ApiError ? e.message : 'Falha na operação.', 'error');
  const rematricular = useMutation({
    mutationFn: (cursoId: number) => http.post<{ message: string }>('/me/rematricula', { cursoId }),
    onSuccess: (r) => { toast(r.message); qc.invalidateQueries({ queryKey: ['minha-rematricula'] }); qc.invalidateQueries({ queryKey: ['meu-financeiro'] }); },
    onError: erro,
  });
  const inscrever = useMutation({
    mutationFn: (cursoId: number) => http.post<{ curso: string }>('/me/inscricoes', { cursoId }),
    onSuccess: (r) => { toast(`Inscrição em ${r.curso} realizada.`); qc.invalidateQueries({ queryKey: ['minha-inscricao'] }); qc.invalidateQueries({ queryKey: ['me-cursos-abertos'] }); },
    onError: erro,
  });

  if (insc.isLoading) return <FullPageSpinner />;
  const i = insc.data;

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold text-slate-900">Minha matrícula</h1>

      {i ? (
        <Card>
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="text-sm text-slate-500">Inscrição de {fmt.date(i.data)}</p>
              <p className="font-medium text-slate-900">{i.cursos.map((c) => c.nome).join(', ') || '—'}</p>
            </div>
            <span className={cx('rounded-full px-2.5 py-1 text-xs font-medium', STATUS_INSCRICAO[i.status]?.cls)}>{STATUS_INSCRICAO[i.status]?.nome}</span>
          </div>
          {i.status === 1 && <p className="mt-3 text-sm text-slate-600">Sua inscrição está em análise pela secretaria. Envie os documentos abaixo para agilizar.</p>}
          {i.status === 3 && i.justificativaReprovacao && <div className="mt-3"><Alert>Motivo: {i.justificativaReprovacao}</Alert></div>}
          <h2 className="mb-2 mt-5 text-sm font-semibold text-slate-900">Documentos</h2>
          <DocumentosUpload
            documentos={Object.fromEntries(i.documentos.map((d) => [d.campo, d.rotulo]))}
            enviados={Object.fromEntries(i.documentos.map((d) => [d.campo, d.enviado]))}
          />
        </Card>
      ) : (
        <Card><p className="text-sm text-slate-500">Nenhuma inscrição encontrada.</p></Card>
      )}

      <section aria-labelledby="rem-titulo" className="space-y-3">
        <h2 id="rem-titulo" className="flex items-center gap-2 font-semibold text-slate-900"><RotateCw className="h-4 w-4 text-primary" /> Rematrícula</h2>
        {rem.data?.length === 0 && <Card><p className="text-sm text-slate-500">Não há rematrícula aberta para seus cursos no momento.</p></Card>}
        {rem.data?.map((r) => (
          <Card key={r.cursoId} className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="font-medium text-slate-900">{r.curso}</p>
              <p className="flex items-center gap-1 text-sm text-slate-500"><CalendarClock className="h-4 w-4" /> Até {fmt.date(r.prazo)} · {r.valor > 0 ? fmt.money(r.valor) : 'bolsa integral'}</p>
            </div>
            {r.realizada ? <span className="text-sm font-medium text-emerald-700">Rematrícula feita</span> : (
              <Button loading={rematricular.isPending && rematricular.variables === r.cursoId}
                onClick={async () => { if (await confirm(`Confirmar rematrícula em ${r.curso}?${r.valor > 0 ? ` Será gerada uma cobrança de ${fmt.money(r.valor)}.` : ''}`)) rematricular.mutate(r.cursoId); }}>
                Rematricular
              </Button>
            )}
          </Card>
        ))}
      </section>

      <section aria-labelledby="novos-titulo" className="space-y-3">
        <h2 id="novos-titulo" className="flex items-center gap-2 font-semibold text-slate-900"><GraduationCap className="h-4 w-4 text-primary" /> Inscrever-se em outro curso</h2>
        <div className="grid gap-3 md:grid-cols-2">
          {(abertos.data ?? []).filter((c) => !meus.has(c.id)).map((c) => (
            <Card key={c.id} className="flex items-center justify-between gap-3">
              <div>
                <p className="font-medium text-slate-900">{c.nome}</p>
                <p className="text-xs text-slate-500">Matrícula {fmt.money(c.valorMatricula)}{c.exigePreRequisito && ' · exige pré-requisito'}</p>
              </div>
              <Button variant="secondary" disabled={c.vagasRestantes === 0} loading={inscrever.isPending && inscrever.variables === c.id}
                onClick={async () => { if (await confirm(`Inscrever-se em ${c.nome}? Será gerada a cobrança da matrícula.`)) inscrever.mutate(c.id); }}>
                Inscrever
              </Button>
            </Card>
          ))}
        </div>
        {abertos.data && abertos.data.filter((c) => !meus.has(c.id)).length === 0 && <Card><p className="text-sm text-slate-500">Nenhum outro curso com inscrições abertas.</p></Card>}
      </section>
    </div>
  );
}
