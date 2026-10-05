import { createContext, useContext, useEffect, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { http } from '../api/client';
import type { InstituicaoTema } from '../lib/types';

const FALLBACK: InstituicaoTema = {
  id: null,
  nome: 'Studying Online',
  titulo: 'Studying Online',
  logo: null,
  corPrimaria: '#3498db',
  celular: null,
};

const ThemeContext = createContext<InstituicaoTema>(FALLBACK);

function hexToRgb(hex: string): string {
  let h = hex.replace('#', '');
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  const n = parseInt(h, 16);
  return `${(n >> 16) & 255} ${(n >> 8) & 255} ${n & 255}`;
}

export function applyTheme(t: InstituicaoTema) {
  document.documentElement.style.setProperty('--primary', hexToRgb(t.corPrimaria));
  document.title = t.titulo || t.nome;
  if (t.logo) {
    const link = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
    if (link) link.href = t.logo.replace('logo', 'favicon');
  }
}

/** Tema white-label da instituição, descoberta pelo subdomínio (antes: LoginController.Index). */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const { data } = useQuery({
    queryKey: ['instituicao-by-host', window.location.hostname],
    queryFn: () => http.get<InstituicaoTema>(`/instituicoes/by-host?host=${encodeURIComponent(window.location.hostname)}`),
    staleTime: Infinity,
    retry: 1,
  });
  const tema = data ?? FALLBACK;

  useEffect(() => {
    applyTheme(tema);
  }, [tema]);

  return <ThemeContext.Provider value={tema}>{children}</ThemeContext.Provider>;
}

export const useTheme = () => useContext(ThemeContext);
