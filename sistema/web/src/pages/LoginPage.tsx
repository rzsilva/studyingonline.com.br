import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { Eye, EyeOff } from 'lucide-react';
import { useAuth } from '../auth/AuthProvider';
import { ApiError } from '../api/client';
import { AuthLayout } from '../layouts/AuthLayout';
import { Alert, Button, Input } from '../components/ui';

const schema = z.object({
  email: z.string().trim().email('Informe um e-mail válido.'),
  senha: z.string().min(1, 'Informe a senha.'),
});
type Form = z.infer<typeof schema>;

export function LoginPage() {
  const { status, login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [erro, setErro] = useState<string | null>(null);
  const [mostrar, setMostrar] = useState(false);
  const { register, handleSubmit, formState } = useForm<Form>({ resolver: zodResolver(schema) });

  if (status === 'authenticated') return <Navigate to="/" replace />;

  const onSubmit = handleSubmit(async ({ email, senha }) => {
    setErro(null);
    try {
      await login(email, senha);
      const from = (location.state as { from?: string } | null)?.from ?? '/';
      navigate(from, { replace: true });
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Não foi possível entrar. Tente novamente.');
    }
  });

  return (
    <AuthLayout title="Entrar" subtitle="Acesse com seu e-mail e senha.">
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {erro && <Alert>{erro}</Alert>}
        <Input
          label="E-mail"
          type="email"
          autoComplete="username"
          autoFocus
          error={formState.errors.email?.message}
          {...register('email')}
        />
        <div className="relative">
          <Input
            label="Senha"
            type={mostrar ? 'text' : 'password'}
            autoComplete="current-password"
            error={formState.errors.senha?.message}
            {...register('senha')}
          />
          <button
            type="button"
            onClick={() => setMostrar((v) => !v)}
            className="absolute right-3 top-[34px] text-slate-400 hover:text-slate-600"
            aria-label={mostrar ? 'Ocultar senha' : 'Mostrar senha'}
          >
            {mostrar ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
          </button>
        </div>
        <div className="flex justify-end">
          <Link to="/esqueci-senha" className="text-sm font-medium text-primary hover:underline">
            Esqueci minha senha
          </Link>
        </div>
        <Button type="submit" className="w-full" loading={formState.isSubmitting}>
          Entrar
        </Button>
        <p className="text-center text-sm text-slate-500">
          Ainda não é aluno? <Link to="/inscricao" className="font-medium text-primary hover:underline">Inscreva-se</Link>
        </p>
      </form>
    </AuthLayout>
  );
}
