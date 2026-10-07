import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, CheckCircle2, Clock, GraduationCap, Users } from 'lucide-react';
import { ApiError, http } from '../../api/client';
import { AuthLayout } from '../../layouts/AuthLayout';
import { Alert, Button, Card, Input, cx } from '../../components/ui';
import { Checkbox, Select } from '../../components/form';
import { fmt } from '../../components/CrudPage';
import { DocumentosUpload } from './DocumentosUpload';

export interface CursoAberto {
  id: number; nome: string; subtitulo: string | null; descricao: string | null; tipo: string | null;
  mensalidade: number; valorMatricula: number; inscricoesAte: string | null; cargaHoraria: number | null;
  duracaoMeses: number | null; vagasRestantes: number | null; exigePreRequisito: boolean;
}
interface Resultado { inscricaoId: number; matricula: string; tokenDocumentos: string; documentos: Record<string, string> }

const UFS = 'AC AL AP AM BA CE DF ES GO MA MT MS MG PA PB PR PE PI RJ RN RS RO RR SC SP SE TO'.split(' ').map((u, i) => ({ id: i + 1, nome: u }));

export function cpfValido(v: string) {
  const c = v.replace(/\D/g, '');
  if (c.length !== 11 || /^(\d)\1{10}$/.test(c)) return false;
  for (let t = 9; t < 11; t++) {
    let s = 0;
    for (let i = 0; i < t; i++) s += Number(c[i]) * (t + 1 - i);
    if (((10 * s) % 11) % 10 !== Number(c[t])) return false;
  }
  return true;
}
const mascaraCpf = (v: string) => v.replace(/\D/g, '').slice(0, 11).replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
const mascaraCel = (v: string) => v.replace(/\D/g, '').slice(0, 11).replace(/^(\d{2})(\d)/, '($1) $2').replace(/(\d{5})(\d)/, '$1-$2');

/** Inscrição pública (antes Views/Inscricao). A instituição é a do endereço acessado. */
export function InscricaoPublicaPage() {
  const cursos = useQuery({ queryKey: ['cursos-abertos'], queryFn: () => http.get<CursoAberto[]>('/publico/cursos') });
  const [curso, setCurso] = useState<CursoAberto | null>(null);
  const [resultado, setResultado] = useState<Resultado | null>(null);

  if (resultado) {
    return (
      <AuthLayout title="Inscrição recebida!" subtitle={`Matrícula nº ${resultado.matricula}`}>
        <div className="space-y-5">
          <Alert kind="success">Enviamos um e-mail de confirmação. A secretaria vai analisar sua inscrição.</Alert>
          <div>
            <h2 className="mb-1 text-sm font-semibold text-slate-900">Envie seus documentos (opcional agora)</h2>
            <p className="mb-3 text-xs text-slate-500">PDF, JPG ou PNG até 20 MB. Você também pode enviar depois, entrando no sistema.</p>
            <DocumentosUpload documentos={resultado.documentos} token={resultado.tokenDocumentos} />
          </div>
          <Link to="/login" className="block text-center text-sm font-medium text-primary hover:underline">Ir para o login</Link>
        </div>
      </AuthLayout>
    );
  }

  if (!curso) {
    return (
      <div className="min-h-screen bg-slate-50 px-4 py-10">
        <div className="mx-auto max-w-5xl">
          <Link to="/login" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-primary"><ArrowLeft className="h-4 w-4" /> Já tenho cadastro</Link>
          <h1 className="mt-3 text-3xl font-semibold text-slate-900">Inscrições abertas</h1>
          <p className="mt-1 text-slate-500">Escolha o curso para começar sua inscrição.</p>
          {cursos.isError && <div className="mt-6"><Alert>{(cursos.error as Error).message}</Alert></div>}
          {cursos.data?.length === 0 && <Card className="mt-6"><p className="text-sm text-slate-500">Não há cursos com inscrições abertas no momento.</p></Card>}
          <div className="mt-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {cursos.data?.map((c) => {
              const esgotado = c.vagasRestantes === 0;
              return (
                <Card key={c.id} className="flex flex-col">
                  <div className="flex items-start gap-3">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><GraduationCap className="h-5 w-5" /></span>
                    <div>
                      <h2 className="font-semibold text-slate-900">{c.nome}</h2>
                      {c.subtitulo && <p className="text-sm text-slate-500">{c.subtitulo}</p>}
                    </div>
                  </div>
                  {c.descricao && <p className="mt-3 line-clamp-3 text-sm text-slate-600">{c.descricao}</p>}
                  <dl className="mt-4 space-y-1 text-sm text-slate-600">
                    <div className="flex justify-between"><dt>Matrícula</dt><dd className="font-medium">{fmt.money(c.valorMatricula)}</dd></div>
                    <div className="flex justify-between"><dt>Mensalidade</dt><dd>{fmt.money(c.mensalidade)}</dd></div>
                    {c.duracaoMeses && <div className="flex justify-between"><dt>Duração</dt><dd>{c.duracaoMeses} meses</dd></div>}
                  </dl>
                  <div className="mt-3 flex flex-wrap gap-3 text-xs text-slate-500">
                    {c.inscricoesAte && <span className="flex items-center gap-1"><Clock className="h-3.5 w-3.5" />Até {fmt.date(c.inscricoesAte)}</span>}
                    {c.vagasRestantes !== null && <span className="flex items-center gap-1"><Users className="h-3.5 w-3.5" />{c.vagasRestantes} vaga(s)</span>}
                  </div>
                  <div className="mt-auto pt-4">
                    {c.exigePreRequisito ? (
                      <p className="rounded-lg bg-amber-50 p-2 text-xs text-amber-800">Exige curso pré-requisito. <Link to="/login" className="font-medium underline">Entre no sistema</Link> para se inscrever.</p>
                    ) : (
                      <Button className="w-full" disabled={esgotado} onClick={() => setCurso(c)}>{esgotado ? 'Vagas esgotadas' : 'Inscrever-se'}</Button>
                    )}
                  </div>
                </Card>
              );
            })}
          </div>
        </div>
      </div>
    );
  }

  return <FormularioInscricao curso={curso} onVoltar={() => setCurso(null)} onSucesso={setResultado} />;
}

function FormularioInscricao({ curso, onVoltar, onSucesso }: { curso: CursoAberto; onVoltar: () => void; onSucesso: (r: Resultado) => void }) {
  const [f, setF] = useState<Record<string, string>>({ formaPagamento: '1' });
  const [aceite, setAceite] = useState(false);
  const [erros, setErros] = useState<Record<string, string>>({});
  const [erroGeral, setErroGeral] = useState<string | null>(null);
  const set = (k: string, mask?: (v: string) => string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setF((s) => ({ ...s, [k]: mask ? mask(e.target.value) : e.target.value }));

  const enviar = useMutation({
    mutationFn: () => http.post<Resultado>('/publico/inscricoes', {
      ...f, cursoId: curso.id, deAcordo: aceite, formaPagamento: Number(f.formaPagamento),
      uf: f.uf ? UFS[Number(f.uf) - 1]?.nome : undefined, questionario: { emergenciaNome: f.emergenciaNome, emergenciaCelular: f.emergenciaCelular },
    }),
    onSuccess: onSucesso,
    onError: (e) => {
      if (e instanceof ApiError && e.status === 422 && Object.keys(e.fields).length) setErros(e.fields);
      else setErroGeral(e instanceof ApiError ? e.message : 'Não foi possível enviar. Tente novamente.');
    },
  });

  const validar = useMemo(() => () => {
    const e: Record<string, string> = {};
    if (!f.nome || f.nome.trim().split(/\s+/).length < 2) e.nome = 'Informe o nome completo.';
    if (!/^\S+@\S+\.\S+$/.test(f.email ?? '')) e.email = 'E-mail inválido.';
    if (!cpfValido(f.cpf ?? '')) e.cpf = 'CPF inválido.';
    if ((f.celular ?? '').replace(/\D/g, '').length < 10) e.celular = 'Informe o celular com DDD.';
    if (!f.senha || f.senha.length < 8 || !/[A-Za-z]/.test(f.senha) || !/\d/.test(f.senha)) e.senha = 'Mínimo de 8 caracteres, com letras e números.';
    if (f.senha !== f.confirmar) e.confirmar = 'As senhas não conferem.';
    if (!aceite) e.deAcordo = 'É preciso aceitar os termos.';
    return e;
  }, [f, aceite]);

  const submit = (ev: React.FormEvent) => {
    ev.preventDefault();
    setErroGeral(null);
    const e = validar();
    setErros(e);
    if (!Object.keys(e).length) enviar.mutate();
  };

  return (
    <div className="min-h-screen bg-slate-50 px-4 py-10">
      <form onSubmit={submit} noValidate className="mx-auto max-w-2xl space-y-5">
        <button type="button" onClick={onVoltar} className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-primary"><ArrowLeft className="h-4 w-4" /> Escolher outro curso</button>
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Inscrição</h1>
          <p className="text-slate-500">{curso.nome} · matrícula {fmt.money(curso.valorMatricula)}</p>
        </div>
        {erroGeral && <Alert>{erroGeral}</Alert>}
        {/* honeypot: invisível para pessoas; robôs costumam preencher */}
        <input type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" value={f.website ?? ''} onChange={set('website')} />

        <Card title="Dados pessoais">
          <div className="grid gap-4 sm:grid-cols-2">
            <Input className="sm:col-span-2" label="Nome completo *" autoComplete="name" value={f.nome ?? ''} onChange={set('nome')} error={erros.nome} />
            <Input label="CPF *" inputMode="numeric" value={f.cpf ?? ''} onChange={set('cpf', mascaraCpf)} error={erros.cpf} />
            <Input label="Data de nascimento" type="date" value={f.dataNascimento ?? ''} onChange={set('dataNascimento')} error={erros.dataNascimento} />
            <Input label="Celular *" inputMode="tel" autoComplete="tel" value={f.celular ?? ''} onChange={set('celular', mascaraCel)} error={erros.celular} />
            <Input label="RG" value={f.rg ?? ''} onChange={set('rg')} />
          </div>
        </Card>
        <Card title="Endereço">
          <div className="grid gap-4 sm:grid-cols-6">
            <Input className="sm:col-span-2" label="CEP" inputMode="numeric" value={f.cep ?? ''} onChange={set('cep')} />
            <Input className="sm:col-span-4" label="Rua" value={f.rua ?? ''} onChange={set('rua')} />
            <Input className="sm:col-span-2" label="Número" inputMode="numeric" value={f.numero ?? ''} onChange={set('numero')} />
            <Input className="sm:col-span-4" label="Bairro" value={f.bairro ?? ''} onChange={set('bairro')} />
            <Input className="sm:col-span-4" label="Cidade" value={f.cidade ?? ''} onChange={set('cidade')} />
            <Select className="sm:col-span-2" label="UF" options={UFS} value={f.uf ?? ''} onChange={set('uf')} />
          </div>
        </Card>
        <Card title="Contato de emergência">
          <div className="grid gap-4 sm:grid-cols-2">
            <Input label="Nome" value={f.emergenciaNome ?? ''} onChange={set('emergenciaNome')} />
            <Input label="Celular" inputMode="tel" value={f.emergenciaCelular ?? ''} onChange={set('emergenciaCelular', mascaraCel)} />
          </div>
        </Card>
        <Card title="Acesso ao sistema">
          <div className="grid gap-4 sm:grid-cols-2">
            <Input className="sm:col-span-2" label="E-mail (será seu login) *" type="email" autoComplete="email" value={f.email ?? ''} onChange={set('email')} error={erros.email} />
            <Input label="Senha *" type="password" autoComplete="new-password" value={f.senha ?? ''} onChange={set('senha')} error={erros.senha} />
            <Input label="Confirmar senha *" type="password" autoComplete="new-password" value={f.confirmar ?? ''} onChange={set('confirmar')} error={erros.confirmar} />
          </div>
        </Card>
        <Card title="Pagamento da matrícula">
          <div className="flex flex-wrap gap-3" role="radiogroup" aria-label="Forma de pagamento">
            {[['1', 'Boleto'], ['2', 'Cartão de crédito']].map(([v, l]) => (
              <label key={v} className={cx('flex cursor-pointer items-center gap-2 rounded-lg border px-4 py-2 text-sm',
                f.formaPagamento === v ? 'border-primary bg-primary/5' : 'border-slate-300')}>
                <input type="radio" name="formaPagamento" value={v} checked={f.formaPagamento === v} onChange={set('formaPagamento')} className="text-primary" />{l}
              </label>
            ))}
          </div>
          <p className="mt-2 text-xs text-slate-500">As instruções de pagamento serão enviadas por e-mail.</p>
        </Card>
        <div>
          <Checkbox label="Declaro que as informações são verdadeiras e aceito os termos de inscrição e o tratamento dos meus dados para fins acadêmicos (LGPD)." checked={aceite} onChange={(e) => setAceite(e.target.checked)} />
          {erros.deAcordo && <p className="mt-1 text-xs text-red-600">{erros.deAcordo}</p>}
        </div>
        <Button type="submit" className="w-full" loading={enviar.isPending}>Enviar inscrição</Button>
        {enviar.isError && (enviar.error as ApiError)?.code === 'ja_cadastrado' && (
          <p className="text-center text-sm"><Link to="/login" className="font-medium text-primary hover:underline">Entrar no sistema</Link> · <Link to="/esqueci-senha" className="text-primary hover:underline">Esqueci minha senha</Link></p>
        )}
        <p className="flex items-center justify-center gap-1 text-center text-xs text-slate-400"><CheckCircle2 className="h-3.5 w-3.5" /> Conexão segura</p>
      </form>
    </div>
  );
}
