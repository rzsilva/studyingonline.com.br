import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { http, refreshSession, setAccessToken, setSessionExpiredHandler } from '../api/client';
import { applyTheme } from '../theme/ThemeProvider';
import type { Me } from '../lib/types';

interface AuthState {
  status: 'loading' | 'authenticated' | 'anonymous';
  user: Me | null;
  login: (email: string, senha: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient();
  const [hasSession, setHasSession] = useState<boolean | null>(null);

  // Ao abrir o app, tenta recuperar a sessão pelo cookie HttpOnly
  useEffect(() => {
    refreshSession().then(setHasSession);
    setSessionExpiredHandler(() => {
      setHasSession(false);
      qc.clear();
    });
  }, [qc]);

  const me = useQuery({
    queryKey: ['me'],
    queryFn: () => http.get<Me>('/me'),
    enabled: hasSession === true,
    staleTime: 60_000,
  });

  useEffect(() => {
    if (me.data) applyTheme(me.data.instituicao);
  }, [me.data]);

  const login = useCallback(
    async (email: string, senha: string) => {
      const data = await http.post<{ accessToken: string }>('/auth/login', { email, senha });
      setAccessToken(data.accessToken);
      await qc.invalidateQueries({ queryKey: ['me'] });
      setHasSession(true);
    },
    [qc],
  );

  const logout = useCallback(async () => {
    await http.post('/auth/logout').catch(() => undefined);
    setAccessToken(null);
    qc.clear();
    setHasSession(false);
  }, [qc]);

  const status: AuthState['status'] =
    hasSession === null || (hasSession && me.isLoading)
      ? 'loading'
      : hasSession && me.data
        ? 'authenticated'
        : 'anonymous';

  return (
    <AuthContext.Provider value={{ status, user: me.data ?? null, login, logout }}>{children}</AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth fora do AuthProvider');
  return ctx;
}
