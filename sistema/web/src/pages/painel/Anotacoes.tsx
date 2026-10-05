import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { StickyNote, Trash2 } from 'lucide-react';
import { ApiError, http } from '../../api/client';
import { Button, Card } from '../../components/ui';
import { useFeedback } from '../../components/overlay';

interface Anotacao { id: number; video: string | null; posicao: string | null; descricao: string; dataCadastro: string }

const POSICAO = /^(\d{1,2}:)?\d{1,2}:\d{2}$/;

/** Anotações pessoais do aluno no módulo (VIDEO_ANOTACAO, agora com dono). */
export function Anotacoes({ moduloId, videoTitulo }: { moduloId: number; videoTitulo?: string }) {
  const qc = useQueryClient();
  const { toast, confirm } = useFeedback();
  const key = ['anotacoes', moduloId];
  const { data = [] } = useQuery({ queryKey: key, queryFn: () => http.get<Anotacao[]>(`/anotacoes?moduloId=${moduloId}&perPage=100`) });
  const [texto, setTexto] = useState('');
  const [posicao, setPosicao] = useState('');

  const salvar = useMutation({
    mutationFn: () => http.post('/anotacoes', {
      disciplinaId: moduloId, video: videoTitulo ?? null, descricao: texto,
      posicao: posicao ? (posicao.split(':').length === 2 ? `00:${posicao.padStart(5, '0')}` : posicao) : null,
    }),
    onSuccess: () => { setTexto(''); setPosicao(''); qc.invalidateQueries({ queryKey: key }); },
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.fields)[0] ?? e.message : 'Falha ao salvar.', 'error'),
  });
  const excluir = async (a: Anotacao) => {
    if (!(await confirm('Excluir esta anotação?', { danger: true }))) return;
    await http.del(`/anotacoes/${a.id}`).catch(() => undefined);
    qc.invalidateQueries({ queryKey: key });
  };

  return (
    <Card title="Minhas anotações">
      <form className="space-y-2" onSubmit={(e) => { e.preventDefault(); if (texto.trim() && (!posicao || POSICAO.test(posicao))) salvar.mutate(); }}>
        <textarea aria-label="Nova anotação" rows={2} value={texto} onChange={(e) => setTexto(e.target.value)} placeholder="Anote algo sobre esta aula…"
          className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15" />
        <div className="flex items-center gap-2">
          <input aria-label="Minuto do vídeo" placeholder="mm:ss" value={posicao} onChange={(e) => setPosicao(e.target.value)}
            className={`w-24 rounded-lg border px-2 py-1.5 text-sm focus:outline-none ${posicao && !POSICAO.test(posicao) ? 'border-red-400' : 'border-slate-300'}`} />
          <Button type="submit" className="ml-auto px-3 py-1.5" loading={salvar.isPending} disabled={!texto.trim()}>Salvar</Button>
        </div>
      </form>
      <ul className="mt-3 space-y-2">
        {data.map((a) => (
          <li key={a.id} className="group flex items-start gap-2 rounded-lg bg-amber-50 p-2 text-sm">
            <StickyNote className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
            <div className="min-w-0 flex-1">
              {(a.posicao || a.video) && <p className="text-xs text-amber-700">{[a.video, a.posicao].filter(Boolean).join(' · ')}</p>}
              <p className="whitespace-pre-line text-slate-700">{a.descricao}</p>
            </div>
            <button type="button" onClick={() => excluir(a)} className="rounded p-1 text-slate-400 opacity-0 hover:text-red-600 group-hover:opacity-100 focus:opacity-100" title="Excluir">
              <Trash2 className="h-3.5 w-3.5" />
            </button>
          </li>
        ))}
        {data.length === 0 && <li className="text-xs text-slate-400">Nenhuma anotação neste módulo.</li>}
      </ul>
    </Card>
  );
}
