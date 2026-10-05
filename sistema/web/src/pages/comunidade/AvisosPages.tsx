import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ImagePlus, Megaphone } from 'lucide-react';
import { ApiError, blobUrl, http, upload } from '../../api/client';
import { CrudPage, fmt, type CrudConfig, type Row } from '../../components/CrudPage';
import { useFeedback } from '../../components/overlay';
import { Card } from '../../components/ui';

export interface Aviso { id: number; titulo: string; descricao: string | null; tipo: string | null; url: string | null; dataCadastro: string | null }

/** Imagem do aviso: URL absoluta do legado ou upload novo ("local:") servido pela API autenticada. */
export function AvisoImagem({ aviso, className }: { aviso: Pick<Aviso, 'id' | 'url'>; className?: string }) {
  const [src, setSrc] = useState<string | null>(null);
  useEffect(() => {
    if (!aviso.url) return setSrc(null);
    if (!aviso.url.startsWith('local:')) return setSrc(/^https?:\/\//.test(aviso.url) ? aviso.url : null);
    let url: string | null = null;
    blobUrl(`/avisos/${aviso.id}/imagem`).then((u) => { url = u; setSrc(u); }).catch(() => setSrc(null));
    return () => { if (url) URL.revokeObjectURL(url); };
  }, [aviso.id, aviso.url]);
  // imagens antigas (FTP legado) podem ter sumido: esconde em vez de deixar espaço quebrado
  return src ? <img src={src} alt="" className={className} loading="lazy" onError={() => setSrc(null)} /> : null;
}

/** Feed da página inicial (antes Home/Index + Aviso/GetFisrt20Records). */
export function AvisosFeed() {
  const { data = [], isLoading } = useQuery({
    queryKey: ['avisos-feed'],
    queryFn: () => http.get<Aviso[]>('/avisos?perPage=10'),
    staleTime: 60_000,
  });
  if (isLoading) return null;
  return (
    <section className="space-y-3" aria-labelledby="avisos-titulo">
      <h2 id="avisos-titulo" className="flex items-center gap-2 text-base font-semibold text-slate-900">
        <Megaphone className="h-5 w-5 text-primary" /> Avisos
      </h2>
      {data.length === 0 ? (
        <Card><p className="text-sm text-slate-500">Nenhum aviso no momento.</p></Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {data.map((a) => (
            <Card key={a.id} className="overflow-hidden p-0">
              <AvisoImagem aviso={a} className="max-h-56 w-full object-cover" />
              <div className="p-5">
                <div className="flex items-start justify-between gap-2">
                  <h3 className="font-semibold text-slate-900">{a.titulo}</h3>
                  {a.tipo && <span className="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">{a.tipo}</span>}
                </div>
                <p className="mt-0.5 text-xs text-slate-500">{fmt.date(a.dataCadastro)}</p>
                {a.descricao && <p className="mt-2 whitespace-pre-line text-sm text-slate-600">{a.descricao}</p>}
              </div>
            </Card>
          ))}
        </div>
      )}
    </section>
  );
}

function ImagemButton({ row, reload }: { row: Row; reload: () => void }) {
  const input = useRef<HTMLInputElement>(null);
  const { toast } = useFeedback();
  const enviar = async (file?: File) => {
    if (!file) return;
    try {
      const fd = new FormData();
      fd.append('arquivo', file);
      await upload(`/avisos/${row.id}/imagem`, fd);
      toast('Imagem enviada.');
      reload();
    } catch (e) {
      toast(e instanceof ApiError ? Object.values(e.fields)[0] ?? e.message : 'Falha no envio.', 'error');
    } finally {
      if (input.current) input.current.value = '';
    }
  };
  return (
    <>
      <input ref={input} type="file" accept=".jpg,.jpeg,.png" className="hidden" onChange={(e) => enviar(e.target.files?.[0])} />
      <button type="button" onClick={() => input.current?.click()} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-primary" title="Enviar imagem">
        <ImagePlus className="h-4 w-4" aria-label="Enviar imagem" />
      </button>
    </>
  );
}

const avisos: CrudConfig = {
  title: 'Avisos',
  subtitle: 'Publicados na página inicial de todos os usuários da instituição.',
  endpoint: '/avisos',
  singular: 'Aviso',
  columns: [
    { key: 'url', label: '', className: 'w-20', render: (r) => <AvisoImagem aviso={r as unknown as Aviso} className="h-10 w-16 rounded object-cover" /> },
    { key: 'titulo', label: 'Título' },
    { key: 'tipo', label: 'Tipo', render: (r) => String(r.tipo ?? '—') },
    { key: 'dataCadastro', label: 'Publicado em', render: (r) => fmt.date(r.dataCadastro) },
  ],
  fields: [
    { name: 'titulo', label: 'Título', required: true, wide: true },
    { name: 'tipo', label: 'Tipo (etiqueta)', help: 'Ex.: Urgente, Evento, Secretaria' },
    { name: 'descricao', label: 'Texto', type: 'textarea', wide: true },
  ],
  rowActions: (row, reload) => <ImagemButton row={row} reload={reload} />,
};

export const AvisosPage = () => <CrudPage config={avisos} />;
