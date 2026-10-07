import type { ReactElement } from 'react';
import { Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom';
import { useAuth } from './auth/AuthProvider';
import { AppShell } from './layouts/AppShell';
import { LoginPage } from './pages/LoginPage';
import { ForgotPasswordPage, ResetPasswordPage } from './pages/PasswordPages';
import { EmMigracaoPage, HomePage, MinhaContaPage, NotFoundPage } from './pages/AppPages';
import {
  AgendamentoPage, ArquivosPage, AulasPage, CursosPage, DisciplinasPage, EstagiosPage, ModulosPage, ProvasPage, VideoAulasPage,
} from './pages/academico/CadastroPages';
import { NotasAlunoPage, NotasModuloPage, TurmasPage } from './pages/academico/TurmaNotasPages';
import { PainelCursosPage, PainelTrilhaPage } from './pages/painel/PainelOnlinePage';
import { AvisosPage } from './pages/comunidade/AvisosPages';
import { ForumPage, TopicoPage } from './pages/comunidade/ForumPages';
import { ChatPage } from './pages/comunidade/ChatPage';
import { MeuFinanceiroPage, PresencialCursoPage, PresencialCursosPage } from './pages/comunidade/PresencialPages';
import { InscricaoPublicaPage } from './pages/secretaria/InscricaoPublicaPage';
import { InscricoesPage, ProfessoresPage, UsuariosPage } from './pages/secretaria/SecretariaPages';
import { MinhaMatriculaPage } from './pages/secretaria/MinhaMatriculaPage';
import { ContasBancariasPage, ContasFixasPage, ContasPagarPage, ContasReceberPage, RelatorioFinanceiroPage } from './pages/financeiro/FinanceiroPages';
import { CobrancasPage, ExtratoBoletosPage, InstituicaoPage } from './pages/adaline/AdalinePages';
import { FullPageSpinner } from './components/ui';
import { NAVIGATION, type NavItem } from './lib/navigation';
import { Perfil } from './lib/types';

function RequireAuth() {
  const { status } = useAuth();
  const location = useLocation();
  if (status === 'loading') return <FullPageSpinner />;
  if (status === 'anonymous') return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  return <Outlet />;
}

/** Esconde a tela de quem não tem o perfil (a API também bloqueia; isto é só UX). */
function Only({ perfis, children }: { perfis: Perfil[]; children: ReactElement }) {
  const { user } = useAuth();
  return user && perfis.includes(user.perfilId) ? children : <Navigate to="/" replace />;
}

const ADMIN = [Perfil.Administrador];
const EQUIPE = [Perfil.Administrador, Perfil.Professor];

/** Telas já migradas (Fases 1–2). As demais rotas do menu mostram "em migração". */
const ROTAS: Record<string, ReactElement> = {
  '/painel/online': <PainelCursosPage />,
  '/agendamento': <AgendamentoPage />,
  '/cursos': <Only perfis={ADMIN}><CursosPage /></Only>,
  '/cursos/todos': <Only perfis={ADMIN}><CursosPage /></Only>,
  '/cursos/meus': <PainelCursosPage />,
  '/modulos': <Only perfis={ADMIN}><ModulosPage /></Only>,
  '/disciplinas': <Only perfis={ADMIN}><DisciplinasPage /></Only>,
  '/video-aulas': <Only perfis={ADMIN}><VideoAulasPage /></Only>,
  '/aulas': <Only perfis={ADMIN}><AulasPage /></Only>,
  '/arquivos': <Only perfis={ADMIN}><ArquivosPage /></Only>,
  '/provas': <Only perfis={ADMIN}><ProvasPage /></Only>,
  '/estagios': <Only perfis={ADMIN}><EstagiosPage /></Only>,
  '/turmas': <Only perfis={ADMIN}><TurmasPage /></Only>,
  '/notas/aluno': <Only perfis={EQUIPE}><NotasAlunoPage /></Only>,
  '/notas/curso': <Only perfis={EQUIPE}><NotasModuloPage /></Only>,
  '/notas/disciplina': <Only perfis={EQUIPE}><NotasModuloPage /></Only>,
  // Fase 3
  '/avisos': <Only perfis={ADMIN}><AvisosPage /></Only>,
  '/forum': <ForumPage />,
  '/chat': <ChatPage />,
  '/painel/presencial': <PresencialCursosPage />,
  '/meu-financeiro': <MeuFinanceiroPage />,
  // Fase 4
  '/inscricoes': <Only perfis={ADMIN}><InscricoesPage /></Only>,
  '/usuarios': <Only perfis={ADMIN}><UsuariosPage /></Only>,
  '/professores': <Only perfis={ADMIN}><ProfessoresPage /></Only>,
  '/minha-matricula': <Only perfis={[Perfil.Aluno]}><MinhaMatriculaPage /></Only>,
  // Fase 5
  '/financeiro/contas-receber': <Only perfis={ADMIN}><ContasReceberPage /></Only>,
  '/financeiro/contas-pagar': <Only perfis={ADMIN}><ContasPagarPage /></Only>,
  '/financeiro/contas-fixas': <Only perfis={ADMIN}><ContasFixasPage /></Only>,
  '/contas-bancarias': <Only perfis={ADMIN}><ContasBancariasPage /></Only>,
  '/relatorios/financeiro': <Only perfis={ADMIN}><RelatorioFinanceiroPage /></Only>,
  '/instituicao': <Only perfis={ADMIN}><InstituicaoPage /></Only>,
  '/cobrancas': <Only perfis={ADMIN}><CobrancasPage /></Only>,
  '/cobrancas/extrato': <Only perfis={ADMIN}><ExtratoBoletosPage /></Only>,
};

const rotasMenu = (items: NavItem[]): string[] =>
  items.flatMap((i) => [...(i.to && i.to !== '/' ? [i.to] : []), ...rotasMenu(i.children ?? [])]);

export function App() {
  const pendentes = rotasMenu(NAVIGATION).filter((to) => !(to in ROTAS));
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/esqueci-senha" element={<ForgotPasswordPage />} />
      <Route path="/redefinir-senha" element={<ResetPasswordPage />} />
      <Route path="/inscricao" element={<InscricaoPublicaPage />} />

      <Route element={<RequireAuth />}>
        <Route element={<AppShell />}>
          <Route index element={<HomePage />} />
          <Route path="minha-conta" element={<MinhaContaPage />} />
          {Object.entries(ROTAS).map(([to, el]) => <Route key={to} path={to} element={el} />)}
          <Route path="/painel/online/:cursoId" element={<PainelTrilhaPage />} />
          <Route path="/painel/presencial/:cursoId" element={<PresencialCursoPage />} />
          <Route path="/forum/:id" element={<TopicoPage />} />
          {pendentes.map((to) => <Route key={to} path={to} element={<EmMigracaoPage />} />)}
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Route>
    </Routes>
  );
}
