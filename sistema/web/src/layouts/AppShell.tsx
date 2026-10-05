import { useEffect, useMemo, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { ChevronDown, GraduationCap, KeyRound, LogOut, Menu, X } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { http } from '../api/client';
import { useAuth } from '../auth/AuthProvider';
import { visibleNav, type NavItem } from '../lib/navigation';
import { cx } from '../components/ui';
import type { Me } from '../lib/types';

function NavEntry({ item, onNavigate, contador }: { item: NavItem; onNavigate: () => void; contador?: number }) {
  const location = useLocation();
  const active = !!item.children?.some((c) => c.to && location.pathname.startsWith(c.to));
  const [open, setOpen] = useState(active);
  const Icon = item.icon;

  const base = 'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition';

  if (item.children) {
    return (
      <li>
        <button type="button" onClick={() => setOpen((o) => !o)} aria-expanded={open}
          className={cx(base, 'text-slate-300 hover:bg-white/5 hover:text-white')}>
          {Icon && <Icon className="h-4 w-4 shrink-0" aria-hidden />}
          <span className="flex-1 text-left">{item.label}</span>
          <ChevronDown className={cx('h-4 w-4 transition-transform', open && 'rotate-180')} aria-hidden />
        </button>
        {open && (
          <ul className="ml-7 mt-1 space-y-0.5 border-l border-white/10 pl-3">
            {item.children.map((c) => (
              <li key={c.to}>
                <NavLink to={c.to!} onClick={onNavigate}
                  className={({ isActive }) => cx('block rounded-md px-2 py-1.5 text-sm transition',
                    isActive ? 'bg-primary/20 text-white' : 'text-slate-400 hover:text-white')}>
                  {c.label}
                </NavLink>
              </li>
            ))}
          </ul>
        )}
      </li>
    );
  }

  return (
    <li>
      <NavLink to={item.to!} end={item.to === '/'} onClick={onNavigate}
        className={({ isActive }) => cx(base, isActive ? 'bg-primary text-white shadow' : 'text-slate-300 hover:bg-white/5 hover:text-white')}>
        {Icon && <Icon className="h-4 w-4 shrink-0" aria-hidden />}
        <span className="flex-1">{item.label}</span>
        {item.badge && <span className="rounded bg-white/15 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide">{item.badge}</span>}
        {!!contador && <span className="rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white" aria-label={`${contador} não lidas`}>{contador > 99 ? '99+' : contador}</span>}
      </NavLink>
    </li>
  );
}

function Avatar({ user }: { user: Me }) {
  const iniciais = user.nome.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
  return user.foto ? (
    <img src={user.foto} alt="" className="h-9 w-9 rounded-full object-cover" />
  ) : (
    <span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary text-sm font-semibold text-white">{iniciais}</span>
  );
}

export function AppShell() {
  const { user, logout } = useAuth();
  const [mobileOpen, setMobileOpen] = useState(false);
  const location = useLocation();
  const nav = useMemo(() => (user ? visibleNav(user) : []), [user]);
  // contador de mensagens não lidas do chat (polling leve)
  const naoLidas = useQuery({
    queryKey: ['chat-nao-lidas'],
    queryFn: () => http.get<{ naoLidas: number }>('/chat/nao-lidas'),
    refetchInterval: 30_000,
    enabled: !!user,
  });

  useEffect(() => {
    setMobileOpen(false);
  }, [location.pathname]);

  if (!user) return null;

  const sidebar = (
    <div className="flex h-full flex-col bg-slate-900">
      <div className="flex h-16 items-center gap-3 border-b border-white/10 px-5">
        {user.instituicao.logo ? (
          <img src={user.instituicao.logo} alt="" className="h-8 w-8 rounded object-contain bg-white p-0.5" />
        ) : (
          <GraduationCap className="h-7 w-7 text-primary" aria-hidden />
        )}
        <span className="truncate text-sm font-semibold text-white">{user.instituicao.titulo}</span>
      </div>
      <nav className="flex-1 overflow-y-auto px-3 py-4" aria-label="Menu principal">
        <ul className="space-y-1">
          {nav.map((item, i) => (
            <NavEntry key={`${item.label}-${i}`} item={item} onNavigate={() => setMobileOpen(false)} contador={item.to === '/chat' ? naoLidas.data?.naoLidas : undefined} />
          ))}
        </ul>
      </nav>
      <div className="border-t border-white/10 p-4 text-[11px] text-slate-500">Studying Online 2.0 · Adaline</div>
    </div>
  );

  return (
    <div className="min-h-screen lg:pl-64">
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 lg:block">{sidebar}</aside>

      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true">
          <div className="absolute inset-0 bg-slate-900/60" onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 left-0 w-72 max-w-[85%]">{sidebar}</aside>
        </div>
      )}

      <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
        <button type="button" className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" onClick={() => setMobileOpen(true)} aria-label="Abrir menu">
          {mobileOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
        </button>
        <div className="flex-1" />
        <div className="flex items-center gap-3">
          <div className="hidden text-right sm:block">
            <p className="text-sm font-medium text-slate-900">{user.nome}</p>
            <p className="text-xs text-slate-500">{user.perfil ?? ''}</p>
          </div>
          <Avatar user={user} />
          <NavLink to="/minha-conta" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Minha conta / trocar senha">
            <KeyRound className="h-5 w-5" aria-label="Minha conta" />
          </NavLink>
          <button type="button" onClick={logout} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Sair">
            <LogOut className="h-5 w-5" aria-label="Sair" />
          </button>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:py-8">
        <Outlet />
      </main>
    </div>
  );
}
