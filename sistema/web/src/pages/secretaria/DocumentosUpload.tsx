import { useRef, useState } from 'react';
import { CheckCircle2, FileUp, Loader2 } from 'lucide-react';
import { ApiError, upload, uploadComToken } from '../../api/client';
import { cx } from '../../components/ui';

/**
 * Envio de documentos da inscrição. Com `token` usa o link público (logo após a inscrição);
 * sem token envia como aluno logado (/me/inscricao/documentos).
 */
export function DocumentosUpload({
  documentos,
  token,
  enviados = {},
  onEnviado,
}: {
  documentos: Record<string, string>;
  token?: string;
  enviados?: Record<string, boolean>;
  onEnviado?: () => void;
}) {
  const [status, setStatus] = useState<Record<string, 'enviando' | 'ok' | string>>({});
  const inputs = useRef<Record<string, HTMLInputElement | null>>({});

  const enviar = async (campo: string, file?: File) => {
    if (!file) return;
    setStatus((s) => ({ ...s, [campo]: 'enviando' }));
    const fd = new FormData();
    fd.append('arquivo', file);
    try {
      const path = token ? `/publico/inscricoes/documentos/${campo.toLowerCase()}` : `/me/inscricao/documentos/${campo.toLowerCase()}`;
      await (token ? uploadComToken(path, token, fd) : upload(path, fd));
      setStatus((s) => ({ ...s, [campo]: 'ok' }));
      onEnviado?.();
    } catch (e) {
      setStatus((s) => ({ ...s, [campo]: e instanceof ApiError ? Object.values(e.fields)[0] ?? e.message : 'Falha no envio.' }));
    }
  };

  return (
    <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
      {Object.entries(documentos).map(([campo, rotulo]) => {
        const st = status[campo];
        const ok = st === 'ok' || (enviados[campo] && st === undefined);
        return (
          <li key={campo} className="flex items-center gap-3 px-4 py-3 text-sm">
            <span className="flex-1">
              <span className="font-medium text-slate-800">{rotulo}</span>
              {st && st !== 'ok' && st !== 'enviando' && <span className="block text-xs text-red-600">{st}</span>}
            </span>
            <input ref={(el) => { inputs.current[campo] = el; }} type="file" accept=".pdf,.jpg,.jpeg,.png" className="hidden"
              onChange={(e) => { enviar(campo, e.target.files?.[0]); e.target.value = ''; }} />
            <button type="button" onClick={() => inputs.current[campo]?.click()} disabled={st === 'enviando'}
              className={cx('inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium',
                ok ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-300 text-slate-700 hover:border-primary hover:text-primary')}>
              {st === 'enviando' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : ok ? <CheckCircle2 className="h-3.5 w-3.5" /> : <FileUp className="h-3.5 w-3.5" />}
              {ok ? 'Enviado · trocar' : 'Enviar'}
            </button>
          </li>
        );
      })}
    </ul>
  );
}
