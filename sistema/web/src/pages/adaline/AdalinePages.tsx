import { useEffect, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, Building2, ExternalLink, FilePlus2, Pencil, Receipt, RefreshCw } from 'lucide-react';
import { ApiError, http } from '../../api/client';
import { Alert, Button, Card, FullPageSpinner, Input, cx } from '../../components/ui';
import { Checkbox } from '../../components/form';
import { Modal, useFeedback } from '../../components/overlay';
import { fmt } from '../../components/CrudPage';

const erroMsg = (e: unknown) => (e instanceof ApiError ? Object.values(e.fields)[0] ?? e.message : 'Falha na operação.');
const fieldErr = (e: unknown, k: string) => (e instanceof ApiError ? e.fields[k] : undefined);

function Cabecalho({ title, subtitle, children }: { title: string; subtitle?: string; children?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
      </div>
      {children && <div className="flex flex-wrap gap-2">{children}</div>}
    </div>
  );
}

function Tabela({ cols, children, vazio }: { cols: string[]; children: ReactNode; vazio: boolean }) {
  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
      <table className="min-w-full divide-y divide-slate-200 text-sm">
        <thead className="bg-slate-50">
          <tr>{cols.map((c) => <th key={c} scope="col" className="px-4 py-3 text-left font-medium text-slate-600">{c}</th>)}</tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {vazio ? <tr><td colSpan={cols.length} className="px-4 py-8 text-center text-slate-500">Nenhum registro.</td></tr> : children}
        </tbody>
      </table>
    </div>
  );
}

const SITUACAO: Record<string, string> = {
  'Em Aberto': 'bg-amber-50 text-amber-700',
  Pago: 'bg-emerald-50 text-emerald-700',
  Cancelado: 'bg-slate-100 text-slate-500',
};
const Selo = ({ s }: { s: string }) => <span className={cx('rounded-full px-2 py-0.5 text-xs font-medium', SITUACAO[s])}>{s}</span>;

/* ===================== Instituição (admin da escola) ===================== */

interface Escola {
  fantasia: string | null; titulo: string | null; razaoSocial: string | null; cnpj: string | null; email: string | null;
  emailCobranca: string | null; telefone: string | null; celular: string | null; cep: string | null; uf: string | null;
  cidade: string | null; bairro: string | null; rua: string | null; numero: string | null; logo: string | null; corPrimaria: string | null;
  url: string; plano: number | null; alunosQtdMax: number | null; vencimento: string | null; cobrarBoletos: boolean; ativo: boolean;
  operadorAdaline: boolean;
}
type CampoEscola = keyof Omit<Escola, 'url' | 'plano' | 'alunosQtdMax' | 'vencimento' | 'cobrarBoletos' | 'ativo' | 'operadorAdaline'>;

const CAMPOS: { k: CampoEscola; label: string; type?: string; wide?: boolean }[] = [
  { k: 'fantasia', label: 'Nome fantasia' }, { k: 'titulo', label: 'Título exibido' },
  { k: 'razaoSocial', label: 'Razão social' }, { k: 'cnpj', label: 'CNPJ' },
  { k: 'email', label: 'E-mail de contato', type: 'email' }, { k: 'emailCobranca', label: 'E-mail para cobrança', type: 'email' },
  { k: 'telefone', label: 'Telefone' }, { k: 'celular', label: 'Celular / WhatsApp' },
  { k: 'cep', label: 'CEP' }, { k: 'uf', label: 'UF' }, { k: 'cidade', label: 'Cidade' }, { k: 'bairro', label: 'Bairro' },
  { k: 'rua', label: 'Rua' }, { k: 'numero', label: 'Número' },
  { k: 'logo', label: 'Endereço do logo (https://)', wide: true },
];

export function InstituicaoPage() {
  const { toast } = useFeedback();
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['instituicao'], queryFn: () => http.get<Escola>('/instituicao') });
  const [form, setForm] = useState<Partial<Escola>>({});
  useEffect(() => {
    if (data) setForm(data);
  }, [data]);
  const salvar = useMutation({
    mutationFn: () => http.put('/instituicao', Object.fromEntries([...CAMPOS.map((c) => c.k), 'corPrimaria'].map((k) => [k, form[k as CampoEscola] ?? null]))),
    onSuccess: () => {
      toast('Dados da instituição salvos. A cor e o logo valem no próximo acesso.');
      qc.invalidateQueries({ queryKey: ['instituicao'] });
    },
    onError: (e) => toast(erroMsg(e), 'error'),
  });
  if (isLoading || !data) return <FullPageSpinner />;
  const set = (k: CampoEscola, v: string) => setForm((f) => ({ ...f, [k]: v }));

  return (
    <>
      <Cabecalho title="Instituição" subtitle={`Endereço do sistema: ${data.url}`} />
      <div className="grid gap-6 lg:grid-cols-3">
        <Card title="Cadastro" className="lg:col-span-2">
          <form className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); salvar.mutate(); }}>
            {CAMPOS.map((c) => (
              <Input key={c.k} name={c.k} label={c.label} type={c.type ?? 'text'} className={cx(c.wide && 'sm:col-span-2')}
                value={form[c.k] ?? ''} error={fieldErr(salvar.error, c.k)} onChange={(e) => set(c.k, e.target.value)} />
            ))}
            <div>
              <label htmlFor="corPrimaria" className="mb-1.5 block text-sm font-medium text-slate-700">Cor principal</label>
              <div className="flex items-center gap-2">
                <input id="corPrimaria" type="color" value={form.corPrimaria || '#2563eb'} onChange={(e) => set('corPrimaria', e.target.value)}
                  className="h-10 w-14 cursor-pointer rounded border border-slate-300" />
                <span className="text-sm text-slate-500">{form.corPrimaria || '—'}</span>
              </div>
              {fieldErr(salvar.error, 'corPrimaria') && <p className="mt-1 text-sm text-red-600">{fieldErr(salvar.error, 'corPrimaria')}</p>}
            </div>
            <div className="flex items-end justify-end sm:col-span-2">
              <Button type="submit" loading={salvar.isPending}>Salvar</Button>
            </div>
          </form>
        </Card>
        <Card title="Contrato com a Adaline">
          <dl className="space-y-3 text-sm">
            <div className="flex justify-between"><dt className="text-slate-500">Situação</dt><dd className="font-medium">{data.ativo ? 'Ativa' : 'Suspensa'}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Plano</dt><dd className="font-medium">{data.plano ?? '—'}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Limite de alunos ativos</dt><dd className="font-medium">{data.alunosQtdMax ?? 'Sem limite'}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Válido até</dt><dd className="font-medium">{fmt.date(data.vencimento)}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Tarifa por boleto</dt><dd className="font-medium">{fmt.bool(data.cobrarBoletos)}</dd></div>
          </dl>
          <p className="mt-4 text-xs text-slate-500">Esses dados são definidos pela Adaline. Dúvidas: financeiro@adaline.com.br</p>
        </Card>
      </div>
    </>
  );
}

/* ===================== Faturas (escola) + painel da Adaline (operador) ===================== */

interface Fatura {
  id: number; instituicaoId: number; instituicao: string; situacao: string; vencimento: string; fechamento: string | null;
  ativos: number | null; inativos: number | null; valorPlano: number; valorExcedente: number; valorBoletos: number; valorTotal: number;
  pagamento: string | null; valorPago: number | null; url2Via: string | null; boletoEmitido: boolean;
}

export function CobrancasPage() {
  const { data: escola } = useQuery({ queryKey: ['instituicao'], queryFn: () => http.get<Escola>('/instituicao') });
  if (!escola) return <FullPageSpinner />;
  return escola.operadorAdaline ? <PainelAdaline /> : <FaturasEscola />;
}

function FaturasEscola() {
  const { data = [], isLoading } = useQuery({ queryKey: ['adaline-faturas'], queryFn: () => http.get<Fatura[]>('/adaline/faturas') });
  const aberta = data.find((f) => f.situacao === 'Em Aberto');
  return (
    <>
      <Cabecalho title="Faturas da Adaline" subtitle="Mensalidade do Studying Online: plano, alunos excedentes e tarifas de boleto." />
      {aberta && (
        <div className="mb-4">
          <Alert>Há fatura em aberto de {fmt.money(aberta.valorTotal)} com vencimento em {fmt.date(aberta.vencimento)}.</Alert>
        </div>
      )}
      {isLoading ? <FullPageSpinner /> : <TabelaFaturas faturas={data} />}
    </>
  );
}

function TabelaFaturas({ faturas, acoes, comEscola }: { faturas: Fatura[]; acoes?: (f: Fatura) => ReactNode; comEscola?: boolean }) {
  const cols = [...(comEscola ? ['Instituição'] : []), 'Vencimento', 'Alunos (ativos/inativos)', 'Plano', 'Excedente', 'Boletos', 'Total', 'Situação', ''];
  return (
    <Tabela cols={cols} vazio={!faturas.length}>
      {faturas.map((f) => (
        <tr key={f.id}>
          {comEscola && <td className="px-4 py-3 font-medium text-slate-900">{f.instituicao}</td>}
          <td className="px-4 py-3">{fmt.date(f.vencimento)}</td>
          <td className="px-4 py-3">{f.ativos ?? '—'} / {f.inativos ?? '—'}</td>
          <td className="px-4 py-3 text-right">{fmt.money(f.valorPlano)}</td>
          <td className="px-4 py-3 text-right">{fmt.money(f.valorExcedente)}</td>
          <td className="px-4 py-3 text-right">{fmt.money(f.valorBoletos)}</td>
          <td className="px-4 py-3 text-right font-medium">{fmt.money(f.valorTotal)}</td>
          <td className="px-4 py-3"><Selo s={f.situacao} />{f.pagamento && <p className="mt-1 text-xs text-slate-500">pago em {fmt.date(f.pagamento)}</p>}</td>
          <td className="px-4 py-3">
            <div className="flex justify-end gap-1">
              {f.url2Via && (
                <a href={f.url2Via} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm font-medium text-primary hover:bg-primary/10">
                  <ExternalLink className="h-4 w-4" /> Boleto
                </a>
              )}
              {acoes?.(f)}
            </div>
          </td>
        </tr>
      ))}
    </Tabela>
  );
}

interface InstAdaline {
  id: number; nome: string; razaoSocial: string | null; cnpj: string | null; emailCobranca: string | null; ativo: boolean;
  plano: number | null; alunosQtdMax: number | null; vencimento: string | null; cobrarBoletos: boolean; url: string;
  ativos: number; inativos: number; faturasAbertas: number;
}

function PainelAdaline() {
  const { toast, confirm } = useFeedback();
  const qc = useQueryClient();
  const [filtro, setFiltro] = useState<number | ''>('');
  const [contrato, setContrato] = useState<InstAdaline | null>(null);
  const [nova, setNova] = useState<InstAdaline | null>(null);
  const insts = useQuery({ queryKey: ['adaline-insts'], queryFn: () => http.get<InstAdaline[]>('/adaline/instituicoes') });
  const faturas = useQuery({
    queryKey: ['adaline-faturas', filtro],
    queryFn: () => http.get<Fatura[]>(`/adaline/faturas${filtro ? `?instituicaoId=${filtro}` : ''}`),
  });
  const recarregar = () => {
    qc.invalidateQueries({ queryKey: ['adaline-faturas'] });
    qc.invalidateQueries({ queryKey: ['adaline-insts'] });
  };
  const acao = useMutation({
    mutationFn: ({ id, tipo }: { id: number; tipo: 'boleto' | 'cancelar' }) => http.post<{ url?: string }>(`/adaline/faturas/${id}/${tipo}`),
    onSuccess: (r, v) => {
      toast(v.tipo === 'boleto' ? 'Boleto emitido.' : 'Fatura cancelada. Os boletos voltam para a próxima cobrança.');
      if (r?.url) window.open(r.url, '_blank', 'noopener');
      recarregar();
    },
    onError: (e) => toast(erroMsg(e), 'error'),
  });
  const sinc = useMutation({
    mutationFn: () => http.post<{ pagas: number }>('/adaline/sincronizar'),
    onSuccess: (r) => { toast(`${r.pagas} fatura(s) baixada(s).`); recarregar(); },
    onError: (e) => toast(erroMsg(e), 'error'),
  });
  const cancelar = async (f: Fatura) => {
    const okk = await confirm(`Cancelar a fatura de ${fmt.money(f.valorTotal)} de ${f.instituicao}?`, { danger: true });
    if (okk) acao.mutate({ id: f.id, tipo: 'cancelar' });
  };

  return (
    <>
      <Cabecalho title="Painel Adaline" subtitle="Contratos, faturas e boletos das instituições.">
        <Button variant="secondary" loading={sinc.isPending} onClick={() => sinc.mutate()}><RefreshCw className="h-4 w-4" /> Atualizar pagamentos</Button>
      </Cabecalho>

      <h2 className="mb-3 flex items-center gap-2 text-base font-semibold text-slate-900"><Building2 className="h-4 w-4" /> Instituições</h2>
      {insts.isLoading ? <FullPageSpinner /> : (
        <Tabela cols={['Instituição', 'Alunos ativos', 'Limite', 'Plano', 'Válido até', 'Faturas abertas', '']} vazio={!insts.data?.length}>
          {insts.data?.map((i) => {
            const vencido = !!i.vencimento && i.vencimento.slice(0, 10) < new Date().toISOString().slice(0, 10);
            return (
              <tr key={i.id} className={cx(!i.ativo && 'opacity-60')}>
                <td className="px-4 py-3"><p className="font-medium text-slate-900">{i.nome}</p><p className="text-xs text-slate-500">{i.url}{!i.ativo && ' · suspensa'}</p></td>
                <td className={cx('px-4 py-3', !!i.alunosQtdMax && i.ativos > i.alunosQtdMax && 'font-medium text-red-600')}>{i.ativos}</td>
                <td className="px-4 py-3">{i.alunosQtdMax ?? '—'}</td>
                <td className="px-4 py-3">{i.plano ?? '—'}</td>
                <td className={cx('px-4 py-3', vencido && 'font-medium text-red-600')}>{fmt.date(i.vencimento)}</td>
                <td className="px-4 py-3">{i.faturasAbertas}</td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-1">
                    <Button variant="ghost" title="Contrato" aria-label={`Contrato de ${i.nome}`} onClick={() => setContrato(i)}><Pencil className="h-4 w-4" /></Button>
                    <Button variant="ghost" title="Nova fatura" aria-label={`Nova fatura de ${i.nome}`} onClick={() => setNova(i)}><FilePlus2 className="h-4 w-4" /></Button>
                  </div>
                </td>
              </tr>
            );
          })}
        </Tabela>
      )}

      <div className="mb-3 mt-8 flex flex-wrap items-center justify-between gap-3">
        <h2 className="flex items-center gap-2 text-base font-semibold text-slate-900"><Receipt className="h-4 w-4" /> Faturas</h2>
        <label className="flex items-center gap-2 text-sm text-slate-600">
          Instituição
          <select value={filtro} onChange={(e) => setFiltro(e.target.value ? Number(e.target.value) : '')} className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
            <option value="">Todas</option>
            {insts.data?.map((i) => <option key={i.id} value={i.id}>{i.nome}</option>)}
          </select>
        </label>
      </div>
      {faturas.isLoading ? <FullPageSpinner /> : (
        <TabelaFaturas comEscola faturas={faturas.data ?? []} acoes={(f) => f.situacao === 'Em Aberto' && (
          <>
            {!f.boletoEmitido && (
              <Button variant="ghost" loading={acao.isPending && acao.variables?.id === f.id && acao.variables.tipo === 'boleto'}
                onClick={() => acao.mutate({ id: f.id, tipo: 'boleto' })}>Emitir boleto</Button>
            )}
            <Button variant="ghost" title="Cancelar" aria-label="Cancelar fatura" onClick={() => cancelar(f)}><Ban className="h-4 w-4 text-red-600" /></Button>
          </>
        )} />
      )}

      {contrato && <ContratoModal inst={contrato} onClose={() => { setContrato(null); recarregar(); }} />}
      {nova && <NovaFaturaModal inst={nova} onClose={() => { setNova(null); recarregar(); }} />}
    </>
  );
}

function ContratoModal({ inst, onClose }: { inst: InstAdaline; onClose: () => void }) {
  const { toast } = useFeedback();
  const [f, setF] = useState({
    ativo: inst.ativo, cobrarBoletos: inst.cobrarBoletos, plano: inst.plano?.toString() ?? '', alunosQtdMax: inst.alunosQtdMax?.toString() ?? '',
    vencimento: inst.vencimento?.slice(0, 10) ?? '', emailCobranca: inst.emailCobranca ?? '',
  });
  const m = useMutation({
    mutationFn: () => http.put(`/adaline/instituicoes/${inst.id}`, f),
    onSuccess: () => { toast('Contrato atualizado.'); onClose(); },
    onError: (e) => toast(erroMsg(e), 'error'),
  });
  return (
    <Modal open title={`Contrato — ${inst.nome}`} onClose={onClose}
      footer={<><Button variant="secondary" onClick={onClose}>Cancelar</Button><Button loading={m.isPending} onClick={() => m.mutate()}>Salvar</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input label="Plano" type="number" min={0} value={f.plano} onChange={(e) => setF({ ...f, plano: e.target.value })} />
        <Input label="Limite de alunos ativos" type="number" min={0} value={f.alunosQtdMax} onChange={(e) => setF({ ...f, alunosQtdMax: e.target.value })} />
        <Input label="Válido até" type="date" value={f.vencimento} error={fieldErr(m.error, 'vencimento')} onChange={(e) => setF({ ...f, vencimento: e.target.value })} />
        <Input label="E-mail de cobrança" type="email" value={f.emailCobranca} error={fieldErr(m.error, 'emailCobranca')} onChange={(e) => setF({ ...f, emailCobranca: e.target.value })} />
        <Checkbox label="Instituição ativa" checked={f.ativo} onChange={(e) => setF({ ...f, ativo: e.target.checked })} />
        <Checkbox label="Cobrar tarifa por boleto" checked={f.cobrarBoletos} onChange={(e) => setF({ ...f, cobrarBoletos: e.target.checked })} />
      </div>
    </Modal>
  );
}

interface Sugestao { ativos: number; inativos: number; excedentes: number; boletosQtd: number; valorBoletos: number; vencimento: string | null }

function NovaFaturaModal({ inst, onClose }: { inst: InstAdaline; onClose: () => void }) {
  const { toast } = useFeedback();
  const sug = useQuery({ queryKey: ['adaline-sugestao', inst.id], queryFn: () => http.get<Sugestao>(`/adaline/instituicoes/${inst.id}/sugestao`) });
  const [f, setF] = useState({ vencimento: '', valorPlano: '', valorExcedente: '' });
  const num = (v: string) => Number(v.replace(',', '.')) || 0;
  const total = num(f.valorPlano) + num(f.valorExcedente) + (sug.data?.valorBoletos ?? 0);
  const m = useMutation({
    mutationFn: () => http.post('/adaline/faturas', { instituicaoId: inst.id, ...f }),
    onSuccess: () => { toast('Fatura criada. Emita o boleto na lista de faturas.'); onClose(); },
    onError: (e) => toast(erroMsg(e), 'error'),
  });
  return (
    <Modal open title={`Nova fatura — ${inst.nome}`} onClose={onClose}
      footer={<><Button variant="secondary" onClick={onClose}>Cancelar</Button><Button loading={m.isPending} onClick={() => m.mutate()}>Criar fatura</Button></>}>
      {sug.isLoading || !sug.data ? <FullPageSpinner /> : (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-4">
            <div><p className="text-slate-500">Ativos</p><p className="font-semibold">{sug.data.ativos}</p></div>
            <div><p className="text-slate-500">Inativos</p><p className="font-semibold">{sug.data.inativos}</p></div>
            <div><p className="text-slate-500">Excedentes</p><p className={cx('font-semibold', sug.data.excedentes > 0 && 'text-red-600')}>{sug.data.excedentes}</p></div>
            <div><p className="text-slate-500">Boletos ({sug.data.boletosQtd})</p><p className="font-semibold">{fmt.money(sug.data.valorBoletos)}</p></div>
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            <Input label="Vencimento" type="date" value={f.vencimento} error={fieldErr(m.error, 'vencimento')} onChange={(e) => setF({ ...f, vencimento: e.target.value })} />
            <Input label="Valor do plano (R$)" inputMode="decimal" value={f.valorPlano} error={fieldErr(m.error, 'valorPlano')} onChange={(e) => setF({ ...f, valorPlano: e.target.value })} />
            <Input label="Excedente (R$)" inputMode="decimal" value={f.valorExcedente} error={fieldErr(m.error, 'valorExcedente')} onChange={(e) => setF({ ...f, valorExcedente: e.target.value })} />
          </div>
          <p className="text-right text-sm">Total da fatura: <strong>{fmt.money(total)}</strong></p>
          <p className="text-xs text-slate-500">As tarifas de boleto ainda não cobradas entram automaticamente nesta fatura.</p>
        </div>
      )}
    </Modal>
  );
}

/* ===================== Extrato de boletos (escola) ===================== */

interface Extrato { id: number; aluno: string | null; valor: number; vencimento: string; emitidoEm: string; cobrado: boolean; faturaId: number | null }

export function ExtratoBoletosPage() {
  const { data = [], isLoading } = useQuery({ queryKey: ['adaline-extrato'], queryFn: () => http.get<Extrato[]>('/adaline/extrato') });
  const pendente = data.filter((e) => !e.cobrado).reduce((s, e) => s + e.valor, 0);
  return (
    <>
      <Cabecalho title="Extrato de boletos" subtitle="Boletos emitidos pelos alunos. A tarifa entra na próxima fatura da Adaline." />
      <Card className="mb-4 flex flex-wrap gap-8">
        <div><p className="text-sm text-slate-500">Boletos emitidos</p><p className="text-xl font-semibold">{data.length}</p></div>
        <div><p className="text-sm text-slate-500">A cobrar na próxima fatura</p><p className="text-xl font-semibold">{fmt.money(pendente)}</p></div>
      </Card>
      {isLoading ? <FullPageSpinner /> : (
        <Tabela cols={['Emitido em', 'Aluno', 'Vencimento do boleto', 'Tarifa', 'Cobrança']} vazio={!data.length}>
          {data.map((e) => (
            <tr key={e.id}>
              <td className="px-4 py-3">{fmt.date(e.emitidoEm)}</td>
              <td className="px-4 py-3">{e.aluno ?? '—'}</td>
              <td className="px-4 py-3">{fmt.date(e.vencimento)}</td>
              <td className="px-4 py-3 text-right">{fmt.money(e.valor)}</td>
              <td className="px-4 py-3">{e.cobrado ? `Fatura nº ${e.faturaId}` : <span className="text-amber-700">Próxima fatura</span>}</td>
            </tr>
          ))}
        </Tabela>
      )}
    </>
  );
}
