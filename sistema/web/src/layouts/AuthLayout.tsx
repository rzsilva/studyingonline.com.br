import type { ReactNode } from 'react';
import { GraduationCap } from 'lucide-react';
import { useTheme } from '../theme/ThemeProvider';

export function AuthLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  const tema = useTheme();
  return (
    <div className="flex min-h-screen">
      <aside className="relative hidden w-1/2 overflow-hidden bg-primary lg:flex lg:flex-col lg:justify-between lg:p-12">
        <div className="absolute inset-0 bg-gradient-to-br from-black/0 to-black/30" aria-hidden />
        <div className="relative flex items-center gap-3 text-white">
          <GraduationCap className="h-8 w-8" aria-hidden />
          <span className="text-lg font-semibold">{tema.nome}</span>
        </div>
        <div className="relative text-white">
          <p className="text-3xl font-semibold leading-tight">Seu curso, onde você estiver.</p>
          <p className="mt-3 max-w-md text-white/80">Aulas, provas, notas e financeiro em um só lugar.</p>
        </div>
        <p className="relative text-xs text-white/60">Studying Online · Adaline Sistemas</p>
      </aside>

      <main className="flex flex-1 items-center justify-center px-4 py-10 sm:px-8">
        <div className="w-full max-w-sm">
          {tema.logo ? (
            <img src={tema.logo} alt={tema.nome} className="mb-8 h-14 w-auto object-contain" />
          ) : (
            <div className="mb-8 flex items-center gap-2 text-primary lg:hidden">
              <GraduationCap className="h-8 w-8" aria-hidden />
              <span className="text-lg font-semibold">{tema.nome}</span>
            </div>
          )}
          <h1 className="text-2xl font-semibold text-slate-900">{title}</h1>
          {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
          <div className="mt-8">{children}</div>
        </div>
      </main>
    </div>
  );
}
