import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, Pencil, Plus, Trash2 } from 'lucide-react';
import { ApiError, http } from '../../api/client';
import { Alert, Button, Input } from '../../components/ui';
import { Checkbox, Select, Textarea, useLista } from '../../components/form';
import { Modal, useFeedback } from '../../components/overlay';
import type { Row } from '../../components/CrudPage';

interface Questao {
  id: number;
  questao: string;
  opcao1: string; opcao2: string; opcao3: string | null; opcao4: string | null; opcao5: string | null;
  correta: string;
  valor: string;
}

const LETRAS = ['A', 'B', 'C', 'D', 'E'] as const;
const vazia = { questao: '', opcao1: '', opcao2: '', opcao3: '', opcao4: '', opcao5: '', correta: 'A', valor: '' };

export function QuestoesModal({ prova, onClose }: { prova: Row; onClose: () => void }) {
  const qc = useQueryClient();
  const { toast, confirm } = useFeedback();
  const key = ['questoes', prova.id];
  const { data: questoes = [], isLoading } = useQuery({
    queryKey: key,
    queryFn: () => http.get<Questao[]>(`/questoes?provaId=${prova.id}&perPage=200`),
  });
  const [editando, setEditando] = useState<Questao | 'nova' | null>(null);
  const [form, setForm] = useState<Record<string, string>>(vazia);
  const [erros, setErros] = useState<Record<string, string>>({});

  const abrir = (q: Questao | 'nova') => {
    setErros({});
    setForm(q === 'nova' ? vazia : Object.fromEntries(Object.entries(vazia).map(([k]) => [k, String((q as unknown as Record<string, unknown>)[k] ?? '')])));
    setEditando(q);
  };

  const salvar = useMutation({
    mutationFn: () => {
      const payload = { ...form, provaId: prova.id };
      return editando === 'nova' ? http.post('/questoes', payload) : http.put(`/questoes/${(editando as Questao).id}`, payload);
    },
    onSuccess: () => {
      toast('Questão salva.');
      setEditando(null);
      qc.invalidateQueries({ queryKey: key });
      qc.invalidateQueries({ queryKey: ['/provas'] });
    },
    onError: (e) => setErros(e instanceof ApiError ? (Object.keys(e.fields).length ? e.fields : { _: e.message }) : { _: 'Falha ao salvar.' }),
  });

  const excluir = async (q: Questao) => {
    if (!(await confirm('Excluir esta questão?', { danger: true }))) return;
    try {
      await http.del(`/questoes/${q.id}`);
      qc.invalidateQueries({ queryKey: key });
      qc.invalidateQueries({ queryKey: ['/provas'] });
    } catch (e) {
      toast(e instanceof ApiError ? e.message : 'Falha ao excluir.', 'error');
    }
  };

  const total = questoes.reduce((s, q) => s + Number(q.valor || 0), 0);
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <Modal open title={`Questões — ${prova.disciplina ?? ''}`} onClose={onClose} size="xl"
      footer={<Button variant="secondary" onClick={onClose}>Fechar</Button>}>
      {editando ? (
        <form className="space-y-4" noValidate onSubmit={(e) => { e.preventDefault(); salvar.mutate(); }}>
          {erros._ && <Alert>{erros._}</Alert>}
          <Textarea label="Enunciado *" value={form.questao} onChange={set('questao')} error={erros.questao} />
          {LETRAS.map((l, i) => (
            <div key={l} className="flex items-start gap-3">
              <label className="mt-9 flex items-center gap-1 text-sm font-semibold text-slate-600" title="Marcar como correta">
                <input type="radio" name="correta" value={l} checked={form.correta === l} onChange={set('correta')} className="text-primary focus:ring-primary/30" />
                {l}
              </label>
              <Input className="flex-1" label={`Alternativa ${l}${i < 2 ? ' *' : ''}`} value={form[`opcao${i + 1}`]} onChange={set(`opcao${i + 1}`)} error={erros[`opcao${i + 1}`]} />
            </div>
          ))}
          {erros.correta && <p className="text-xs text-red-600">{erros.correta}</p>}
          <Input label="Valor da questão *" inputMode="decimal" className="max-w-[180px]" value={form.valor} onChange={set('valor')} error={erros.valor} />
          <div className="flex justify-end gap-2">
            <Button variant="secondary" type="button" onClick={() => setEditando(null)}>Voltar</Button>
            <Button type="submit" loading={salvar.isPending}>Salvar questão</Button>
          </div>
        </form>
      ) : (
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <p className="text-sm text-slate-500">{questoes.length} questão(ões) · valor total <strong>{total.toLocaleString('pt-BR')}</strong></p>
            <Button onClick={() => abrir('nova')}><Plus className="h-4 w-4" /> Nova questão</Button>
          </div>
          {isLoading && <p className="text-sm text-slate-400">Carregando…</p>}
          <ol className="space-y-3">
            {questoes.map((q, idx) => (
              <li key={q.id} className="rounded-lg border border-slate-200 p-4">
                <div className="flex items-start justify-between gap-3">
                  <p className="font-medium text-slate-900">{idx + 1}. {q.questao}</p>
                  <div className="flex shrink-0 gap-1">
                    <button type="button" onClick={() => abrir(q)} className="rounded p-1.5 text-slate-500 hover:bg-slate-100" title="Editar"><Pencil className="h-4 w-4" /></button>
                    <button type="button" onClick={() => excluir(q)} className="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600" title="Excluir"><Trash2 className="h-4 w-4" /></button>
                  </div>
                </div>
                <ul className="mt-2 space-y-1 text-sm">
                  {LETRAS.map((l, i) => {
                    const t = (q as unknown as Record<string, string | null>)[`opcao${i + 1}`];
                    return t ? (
                      <li key={l} className={q.correta === l ? 'font-medium text-emerald-700' : 'text-slate-600'}>
                        {l}) {t} {q.correta === l && <CheckCircle2 className="inline h-4 w-4" aria-label="correta" />}
                      </li>
                    ) : null;
                  })}
                </ul>
                <p className="mt-2 text-xs text-slate-500">Valor: {Number(q.valor).toLocaleString('pt-BR')}</p>
              </li>
            ))}
          </ol>
        </div>
      )}
    </Modal>
  );
}

export function CopiarProvaModal({ prova, onClose }: { prova: Row; onClose: () => void }) {
  const { toast } = useFeedback();
  const qc = useQueryClient();
  const [cursoId, setCursoId] = useState('');
  const [selecionados, setSelecionados] = useState<number[]>([]);
  const cursos = useLista('cursos');
  const modulos = useLista(cursoId ? 'modulos' : null, `?cursoId=${cursoId}`);

  const copiar = useMutation({
    mutationFn: () => http.post<{ copias: number }>(`/provas/${prova.id}/copiar`, { moduloIds: selecionados }),
    onSuccess: (r) => {
      toast(`Prova copiada para ${r.copias} módulo(s).`);
      qc.invalidateQueries({ queryKey: ['/provas'] });
      onClose();
    },
    onError: (e) => toast(e instanceof ApiError ? e.message : 'Falha ao copiar.', 'error'),
  });

  return (
    <Modal open title="Copiar prova para outros módulos" onClose={onClose}
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button disabled={!selecionados.length} loading={copiar.isPending} onClick={() => copiar.mutate()}>Copiar</Button>
      </>}>
      <div className="space-y-4">
        <Select label="Curso de destino" options={cursos.data ?? []} value={cursoId} onChange={(e) => { setCursoId(e.target.value); setSelecionados([]); }} />
        {(modulos.data ?? []).filter((m) => m.id !== prova.disciplinaId).map((m) => (
          <Checkbox key={m.id} label={m.nome} checked={selecionados.includes(m.id)}
            onChange={(e) => setSelecionados((s) => (e.target.checked ? [...s, m.id] : s.filter((x) => x !== m.id)))} />
        ))}
        {cursoId && modulos.data?.length === 0 && <p className="text-sm text-slate-500">Este curso não tem módulos.</p>}
      </div>
    </Modal>
  );
}
