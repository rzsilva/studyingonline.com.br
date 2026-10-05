import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useSearchParams } from 'react-router-dom';
import { ApiError, http } from '../api/client';
import { AuthLayout } from '../layouts/AuthLayout';
import { Alert, Button, Card, Input } from '../components/ui';

/** Espelha App\Domain\Auth\PasswordPolicy no backend. */
export const novaSenhaSchema = z
  .string()
  .min(8, 'Mínimo de 8 caracteres.')
  .max(128, 'Senha muito longa.')
  .regex(/[A-Za-z]/, 'Use letras e números.')
  .regex(/\d/, 'Use letras e números.');

function errorMessage(e: unknown) {
  if (e instanceof ApiError) return Object.values(e.fields)[0] ?? e.message;
  return 'Falha na comunicação com o servidor.';
}

export function ForgotPasswordPage() {
  const [msg, setMsg] = useState<{ kind: 'error' | 'success'; text: string } | null>(null);
  const { register, handleSubmit, formState } = useForm<{ email: string }>({
    resolver: zodResolver(z.object({ email: z.string().trim().email('Informe um e-mail válido.') })),
  });

  const onSubmit = handleSubmit(async ({ email }) => {
    try {
      const r = await http.post<{ message: string }>('/auth/esqueci-senha', { email });
      setMsg({ kind: 'success', text: r.message });
    } catch (e) {
      setMsg({ kind: 'error', text: errorMessage(e) });
    }
  });

  return (
    <AuthLayout title="Recuperar senha" subtitle="Enviaremos um link para você criar uma nova senha.">
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {msg && <Alert kind={msg.kind}>{msg.text}</Alert>}
        <Input label="E-mail" type="email" autoFocus error={formState.errors.email?.message} {...register('email')} />
        <Button type="submit" className="w-full" loading={formState.isSubmitting}>
          Enviar link
        </Button>
        <p className="text-center text-sm">
          <Link to="/login" className="font-medium text-primary hover:underline">
            Voltar ao login
          </Link>
        </p>
      </form>
    </AuthLayout>
  );
}

const resetSchema = z
  .object({ novaSenha: novaSenhaSchema, confirmar: z.string() })
  .refine((d) => d.novaSenha === d.confirmar, { message: 'As senhas não conferem.', path: ['confirmar'] });

export function ResetPasswordPage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const [msg, setMsg] = useState<{ kind: 'error' | 'success'; text: string } | null>(null);
  const { register, handleSubmit, formState } = useForm<z.infer<typeof resetSchema>>({ resolver: zodResolver(resetSchema) });

  const onSubmit = handleSubmit(async ({ novaSenha }) => {
    try {
      const r = await http.post<{ message: string }>('/auth/redefinir-senha', { token, novaSenha });
      setMsg({ kind: 'success', text: r.message });
    } catch (e) {
      setMsg({ kind: 'error', text: errorMessage(e) });
    }
  });

  return (
    <AuthLayout title="Nova senha" subtitle="Use pelo menos 8 caracteres, com letras e números.">
      {!token ? (
        <Alert>Link inválido. Solicite um novo em “Esqueci minha senha”.</Alert>
      ) : msg?.kind === 'success' ? (
        <div className="space-y-4">
          <Alert kind="success">{msg.text}</Alert>
          <Link to="/login" className="block text-center text-sm font-medium text-primary hover:underline">
            Ir para o login
          </Link>
        </div>
      ) : (
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          {msg && <Alert>{msg.text}</Alert>}
          <Input label="Nova senha" type="password" autoComplete="new-password" error={formState.errors.novaSenha?.message} {...register('novaSenha')} />
          <Input label="Confirmar senha" type="password" autoComplete="new-password" error={formState.errors.confirmar?.message} {...register('confirmar')} />
          <Button type="submit" className="w-full" loading={formState.isSubmitting}>
            Salvar nova senha
          </Button>
        </form>
      )}
    </AuthLayout>
  );
}

const changeSchema = z
  .object({ senhaAtual: z.string().min(1, 'Informe a senha atual.'), novaSenha: novaSenhaSchema, confirmar: z.string() })
  .refine((d) => d.novaSenha === d.confirmar, { message: 'As senhas não conferem.', path: ['confirmar'] });

/** Substitui /Home/TrocarSenha. */
export function ChangePasswordCard() {
  const [msg, setMsg] = useState<{ kind: 'error' | 'success'; text: string } | null>(null);
  const { register, handleSubmit, formState, reset } = useForm<z.infer<typeof changeSchema>>({ resolver: zodResolver(changeSchema) });

  const onSubmit = handleSubmit(async ({ senhaAtual, novaSenha }) => {
    try {
      const r = await http.put<{ message: string }>('/me/senha', { senhaAtual, novaSenha });
      setMsg({ kind: 'success', text: r.message });
      reset();
    } catch (e) {
      setMsg({ kind: 'error', text: errorMessage(e) });
    }
  });

  return (
    <Card title="Trocar senha" className="max-w-lg">
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {msg && <Alert kind={msg.kind}>{msg.text}</Alert>}
        <Input label="Senha atual" type="password" autoComplete="current-password" error={formState.errors.senhaAtual?.message} {...register('senhaAtual')} />
        <Input label="Nova senha" type="password" autoComplete="new-password" error={formState.errors.novaSenha?.message} {...register('novaSenha')} />
        <Input label="Confirmar nova senha" type="password" autoComplete="new-password" error={formState.errors.confirmar?.message} {...register('confirmar')} />
        <Button type="submit" loading={formState.isSubmitting}>
          Salvar
        </Button>
      </form>
    </Card>
  );
}
