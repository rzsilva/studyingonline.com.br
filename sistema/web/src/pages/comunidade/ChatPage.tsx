import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Check, CheckCheck, Search, Send } from 'lucide-react';
import { ApiError, http } from '../../api/client';
import { Alert, cx } from '../../components/ui';
import { useFeedback } from '../../components/overlay';
import { Avatar } from './ForumPages';

interface Contato { id: number; nome: string; foto: string | null; perfil: string | null; online: boolean; naoLidas: number }
interface Mensagem { id: number; minha: boolean; texto: string; data: string; lida: boolean }

const POLL_MS = 5000;
const hora = (d: string) => new Date(d.replace(' ', 'T')).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
const dia = (d: string) => new Date(d.replace(' ', 'T')).toLocaleDateString('pt-BR', { day: '2-digit', month: 'long' });

/** Chat 1:1 com polling (a hospedagem compartilhada não mantém WebSocket). */
export function ChatPage() {
  const qc = useQueryClient();
  const [contatoId, setContatoId] = useState<number | null>(null);
  const [busca, setBusca] = useState('');
  const contatos = useQuery({
    queryKey: ['chat-contatos'],
    queryFn: () => http.get<Contato[]>('/chat/contatos'),
    refetchInterval: 15000,
  });
  const lista = useMemo(
    () => (contatos.data ?? []).filter((c) => c.nome.toLowerCase().includes(busca.toLowerCase())),
    [contatos.data, busca],
  );
  const contato = contatos.data?.find((c) => c.id === contatoId) ?? null;

  if (contatos.isError) return <Alert>{(contatos.error as Error).message}</Alert>;

  return (
    <div className="flex h-[calc(100vh-8.5rem)] min-h-[420px] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <aside className={cx('flex w-full flex-col border-r border-slate-200 md:w-80', contato && 'hidden md:flex')}>
        <div className="border-b border-slate-200 p-3">
          <h1 className="mb-2 text-lg font-semibold text-slate-900">Chat</h1>
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input type="search" aria-label="Buscar contato" placeholder="Buscar contato" value={busca} onChange={(e) => setBusca(e.target.value)}
              className="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15" />
          </div>
        </div>
        <ul className="flex-1 overflow-y-auto">
          {lista.map((c) => (
            <li key={c.id}>
              <button type="button" onClick={() => { setContatoId(c.id); qc.invalidateQueries({ queryKey: ['chat-contatos'] }); }}
                className={cx('flex w-full items-center gap-3 px-3 py-3 text-left hover:bg-slate-50', c.id === contatoId && 'bg-primary/5')}>
                <span className="relative">
                  <Avatar nome={c.nome} foto={c.foto} />
                  {c.online && <span className="absolute bottom-0 right-0 h-2.5 w-2.5 rounded-full border-2 border-white bg-emerald-500" aria-label="online" />}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-slate-900">{c.nome}</span>
                  <span className="block text-xs text-slate-500">{c.perfil}</span>
                </span>
                {c.naoLidas > 0 && <span className="rounded-full bg-primary px-2 py-0.5 text-xs font-semibold text-white">{c.naoLidas}</span>}
              </button>
            </li>
          ))}
          {lista.length === 0 && <li className="p-6 text-center text-sm text-slate-500">Nenhum contato.</li>}
        </ul>
      </aside>
      <section className={cx('flex-1 flex-col', contato ? 'flex' : 'hidden md:flex')}>
        {contato ? <Conversa contato={contato} onVoltar={() => setContatoId(null)} /> : (
          <div className="flex flex-1 items-center justify-center text-sm text-slate-400">Selecione um contato para conversar.</div>
        )}
      </section>
    </div>
  );
}

function Conversa({ contato, onVoltar }: { contato: Contato; onVoltar: () => void }) {
  const qc = useQueryClient();
  const { toast } = useFeedback();
  const [msgs, setMsgs] = useState<Mensagem[]>([]);
  const [texto, setTexto] = useState('');
  const fim = useRef<HTMLDivElement>(null);
  const ultimo = useRef(0);

  // carga inicial + polling incremental (?depois=último id)
  useEffect(() => {
    let ativo = true;
    setMsgs([]);
    ultimo.current = 0;
    const buscar = async () => {
      try {
        const novas = await http.get<Mensagem[]>(`/chat/${contato.id}?depois=${ultimo.current}`);
        if (!ativo) return;
        if (novas.length) {
          ultimo.current = novas[novas.length - 1].id;
          setMsgs((m) => [...m.filter((x) => !novas.some((n) => n.id === x.id)), ...novas]);
          qc.invalidateQueries({ queryKey: ['chat-nao-lidas'] });
        }
      } catch { /* mantém o polling */ }
    };
    buscar();
    const t = setInterval(() => document.visibilityState === 'visible' && buscar(), POLL_MS);
    return () => { ativo = false; clearInterval(t); };
  }, [contato.id, qc]);

  // corpo em bloco: no Chrome atual scrollIntoView() devolve uma Promise, que o React
  // trataria como função de limpeza do efeito ("n is not a function")
  useEffect(() => {
    fim.current?.scrollIntoView({ block: 'end' });
  }, [msgs.length]);

  const enviar = useMutation({
    mutationFn: () => http.post<Mensagem>(`/chat/${contato.id}`, { texto }),
    onSuccess: (m) => {
      setTexto('');
      ultimo.current = Math.max(ultimo.current, m.id);
      setMsgs((s) => [...s, m]);
    },
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.fields)[0] ?? e.message : 'Falha ao enviar.', 'error'),
  });

  let diaAnterior = '';
  return (
    <>
      <header className="flex items-center gap-3 border-b border-slate-200 px-4 py-3">
        <button type="button" onClick={onVoltar} className="rounded-lg p-1 text-slate-500 hover:bg-slate-100 md:hidden" aria-label="Voltar"><ArrowLeft className="h-5 w-5" /></button>
        <Avatar nome={contato.nome} foto={contato.foto} size="sm" />
        <div>
          <p className="text-sm font-semibold text-slate-900">{contato.nome}</p>
          <p className="text-xs text-slate-500">{contato.online ? 'online' : contato.perfil}</p>
        </div>
      </header>
      <div className="flex-1 space-y-1 overflow-y-auto bg-slate-50 px-4 py-4" aria-live="polite">
        {msgs.length === 0 && <p className="pt-10 text-center text-sm text-slate-400">Nenhuma mensagem. Diga olá!</p>}
        {msgs.map((m) => {
          const d = dia(m.data);
          const sep = d !== diaAnterior;
          diaAnterior = d;
          return (
            <div key={m.id}>
              {sep && <p className="my-3 text-center text-xs text-slate-400">{d}</p>}
              <div className={cx('flex', m.minha ? 'justify-end' : 'justify-start')}>
                <div className={cx('max-w-[80%] rounded-2xl px-3 py-2 text-sm shadow-sm',
                  m.minha ? 'rounded-br-sm bg-primary text-white' : 'rounded-bl-sm bg-white text-slate-800')}>
                  <p className="whitespace-pre-wrap break-words">{m.texto}</p>
                  <p className={cx('mt-0.5 flex items-center justify-end gap-1 text-[10px]', m.minha ? 'text-white/70' : 'text-slate-400')}>
                    {hora(m.data)}
                    {m.minha && (m.lida ? <CheckCheck className="h-3 w-3" aria-label="lida" /> : <Check className="h-3 w-3" aria-label="enviada" />)}
                  </p>
                </div>
              </div>
            </div>
          );
        })}
        <div ref={fim} />
      </div>
      <form onSubmit={(e) => { e.preventDefault(); if (texto.trim()) enviar.mutate(); }} className="flex items-end gap-2 border-t border-slate-200 p-3">
        <textarea aria-label="Mensagem" rows={1} value={texto} maxLength={2000} placeholder="Escreva uma mensagem…"
          onChange={(e) => setTexto(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (texto.trim()) enviar.mutate(); } }}
          className="max-h-32 flex-1 resize-none rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15" />
        <button type="submit" disabled={!texto.trim() || enviar.isPending} className="rounded-xl bg-primary p-2.5 text-white disabled:opacity-50" aria-label="Enviar">
          <Send className="h-4 w-4" />
        </button>
      </form>
    </>
  );
}
