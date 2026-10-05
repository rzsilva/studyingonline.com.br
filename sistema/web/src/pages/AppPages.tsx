import { Link } from 'react-router-dom';
import { AlertTriangle, Construction } from 'lucide-react';
import { useAuth } from '../auth/AuthProvider';
import { Card } from '../components/ui';
import { ChangePasswordCard } from './PasswordPages';
import { Perfil } from '../lib/types';
import { AvisosFeed } from './comunidade/AvisosPages';

export function HomePage() {
  const { user } = useAuth();
  if (!user) return null;
  const bloqueado = user.perfilId === Perfil.Aluno && (user.inativo || user.pendenciaFinanceira);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Olá, {user.nome.split(' ')[0]}!</h1>
        <p className="mt-1 text-sm text-slate-500">Bem-vindo(a) ao {user.instituicao.nome}.</p>
      </div>

      {bloqueado && (
        <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800" role="alert">
          <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" aria-hidden />
          <div>
            <p className="font-medium">{user.inativo ? 'Sua matrícula está inativa.' : 'Há pendência financeira em seu cadastro.'}</p>
            <p className="mt-1">
              O acesso ao painel de aulas e ao fórum está suspenso. Fale com a secretaria
              {user.instituicao.celular ? ` pelo ${user.instituicao.celular}` : ''}.
              {user.pendenciaFinanceira && <> Veja seus títulos em <Link to="/meu-financeiro" className="font-medium underline">Financeiro</Link>.</>}
            </p>
          </div>
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Card title="Seu perfil">
          <dl className="space-y-1 text-sm">
            <div className="flex justify-between"><dt className="text-slate-500">Perfil</dt><dd>{user.perfil}</dd></div>
            {user.matricula && <div className="flex justify-between"><dt className="text-slate-500">Matrícula</dt><dd>{user.matricula}</dd></div>}
            <div className="flex justify-between"><dt className="text-slate-500">E-mail</dt><dd className="truncate pl-4">{user.email}</dd></div>
          </dl>
        </Card>
        {user.perfilId === Perfil.Administrador && user.instituicao.vencimento && (
          <Card title="Assinatura">
            <p className="text-sm text-slate-600">
              Vencimento: <strong>{new Date(user.instituicao.vencimento).toLocaleDateString('pt-BR')}</strong>
            </p>
          </Card>
        )}
        <Card title="Mensagens">
          <p className="text-sm text-slate-600">
            {user.mensagensNaoLidas > 0 ? `${user.mensagensNaoLidas} mensagem(ns) não lida(s) no chat.` : 'Nenhuma mensagem nova.'}
          </p>
        </Card>
      </div>

      <AvisosFeed />
    </div>
  );
}

export function MinhaContaPage() {
  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold text-slate-900">Minha conta</h1>
      <ChangePasswordCard />
    </div>
  );
}

/** Módulos ainda não migrados (fases 2–6 do plano). */
export function EmMigracaoPage() {
  return (
    <Card className="mx-auto mt-10 max-w-lg text-center">
      <Construction className="mx-auto h-10 w-10 text-primary" aria-hidden />
      <h1 className="mt-3 text-lg font-semibold text-slate-900">Módulo em migração</h1>
      <p className="mt-1 text-sm text-slate-500">Esta tela está sendo modernizada e estará disponível em breve.</p>
      <Link to="/" className="mt-4 inline-block text-sm font-medium text-primary hover:underline">Voltar ao início</Link>
    </Card>
  );
}

export function NotFoundPage() {
  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center text-center">
      <p className="text-5xl font-bold text-primary">404</p>
      <p className="mt-2 text-slate-600">Página não encontrada.</p>
      <Link to="/" className="mt-4 text-sm font-medium text-primary hover:underline">Voltar ao início</Link>
    </div>
  );
}
