import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { CheckCircle2, X, XCircle } from 'lucide-react';
import { Button, cx } from './ui';

export function Modal({
  open,
  title,
  onClose,
  children,
  footer,
  size = 'md',
}: {
  open: boolean;
  title: string;
  onClose: () => void;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'md' | 'lg' | 'xl';
}) {
  const ref = useRef<HTMLDivElement>(null);
  const bodyRef = useRef<HTMLDivElement>(null);
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  // Só na abertura: foca o primeiro campo do conteúdo (não o botão fechar) e liga o Esc.
  // Depender de onClose (recriado a cada render) roubava o foco a cada tecla digitada.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onCloseRef.current();
    document.addEventListener('keydown', onKey);
    const first = bodyRef.current?.querySelector<HTMLElement>('input:not([type=hidden]),select,textarea')
      ?? ref.current?.querySelector<HTMLElement>('footer button');
    first?.focus();
    return () => document.removeEventListener('keydown', onKey);
  }, [open]);

  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label={title}>
      <div className="absolute inset-0 bg-slate-900/50" onClick={onClose} />
      <div
        ref={ref}
        className={cx(
          'relative flex max-h-[92vh] w-full flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl',
          { md: 'sm:max-w-lg', lg: 'sm:max-w-2xl', xl: 'sm:max-w-4xl' }[size],
        )}
      >
        <header className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
          <h2 className="text-base font-semibold text-slate-900">{title}</h2>
          <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Fechar">
            <X className="h-5 w-5" />
          </button>
        </header>
        <div ref={bodyRef} className="overflow-y-auto px-5 py-4">{children}</div>
        {footer && <footer className="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">{footer}</footer>}
      </div>
    </div>
  );
}

/* ---------- Toasts + confirmação ---------- */

type Toast = { id: number; kind: 'success' | 'error'; text: string };
type ConfirmState = { text: string; danger?: boolean; resolve: (v: boolean) => void } | null;

const FeedbackContext = createContext<{
  toast: (text: string, kind?: Toast['kind']) => void;
  confirm: (text: string, opts?: { danger?: boolean }) => Promise<boolean>;
} | null>(null);

export function FeedbackProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const [confirmState, setConfirm] = useState<ConfirmState>(null);

  const toast = useCallback((text: string, kind: Toast['kind'] = 'success') => {
    const id = Date.now() + Math.random();
    setToasts((t) => [...t, { id, kind, text }]);
    setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 4500);
  }, []);

  const confirm = useCallback(
    (text: string, opts?: { danger?: boolean }) => new Promise<boolean>((resolve) => setConfirm({ text, danger: opts?.danger, resolve })),
    [],
  );

  const close = (v: boolean) => {
    confirmState?.resolve(v);
    setConfirm(null);
  };

  return (
    <FeedbackContext.Provider value={{ toast, confirm }}>
      {children}
      <div className="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2" aria-live="polite">
        {toasts.map((t) => (
          <div key={t.id} className={cx('pointer-events-auto flex items-start gap-2 rounded-lg px-4 py-3 text-sm text-white shadow-lg',
            t.kind === 'success' ? 'bg-emerald-600' : 'bg-red-600')}>
            {t.kind === 'success' ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" /> : <XCircle className="mt-0.5 h-4 w-4 shrink-0" />}
            {t.text}
          </div>
        ))}
      </div>
      <Modal open={!!confirmState} title="Confirmação" onClose={() => close(false)}
        footer={<>
          <Button variant="secondary" onClick={() => close(false)}>Cancelar</Button>
          <Button variant={confirmState?.danger ? 'danger' : 'primary'} onClick={() => close(true)}>Confirmar</Button>
        </>}>
        <p className="text-sm text-slate-600">{confirmState?.text}</p>
      </Modal>
    </FeedbackContext.Provider>
  );
}

export function useFeedback() {
  const ctx = useContext(FeedbackContext);
  if (!ctx) throw new Error('useFeedback fora do FeedbackProvider');
  return ctx;
}
