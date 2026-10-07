import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, CalendarPlus, CheckCircle2, Download, ExternalLink, KeyRound, Link2, RefreshCw } from 'lucide-react';
import { ApiError, abrirArquivo, http } from '../../api/client';
import { Alert, Button, Card, Input, cx } from '../../components/ui';
import { Textarea } from '../../components/form';
import { Modal, useFeedback } from '../../components/overlay';
import { CrudPage, fmt, type CrudConfig, type Row } from '../../components/CrudPage';

const erroMsg = (e: unknown) => (e instanceof ApiError ? Object.values(e.fields)[0] ?? e.message : 'Falha na operação.');
const competenciaAtual = () => new Date().toISOString().slice(0, 7);

/* ===================== Contas a receber ===================== */

const SITUACAO: Record<number, string> = { 1: 'bg-amber-50 text-amber-700', 2: 'bg-emerald-50 text-emerald-700', 3: 'bg-red-50 text-red-700', 4: 'bg-slate-100 text-slate-500' };

function AcoesTitulo({ row, reload, onBaixa, onCancelar }: { row: Row; reload: () => void; onBaixa: (r: Row) => void; onCancelar: (r: Row) => void }) {
  const { toast } = useFeedback();
  const aberto = ![2, 4].includes(Number(row.listaSituacaoCrId));
  const cobrar = async () => {
    try {
      const r = await http.post<{ url: string }>(`/contas-receber/${row.id}/pagar`, { forma: 'boleto' });
      window.open(r.url, '_blank', 'noopener');
      reload();
    } catch (e) {
      toast(erroMsg(e), 'error');
    }
  };
  if (!aberto) return null;
  return (
    <>
      <button type="button" onClick={cobrar} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-primary" title={row.cobrancaEmitida ? '2ª via' : 'Emitir cobrança'}>
        <ExternalLink className="h-4 w-4" aria-label="Cobrança" />
      </button>
      <button type="button" onClick={() => onBaixa(row)} className="rounded-lg p-2 text-slate-500 hover:bg-emerald-50 hover:text-emerald-700" title="Baixa manual">
        <CheckCircle2 className="h-4 w-4" aria-label="Baixa manual" />
      </button>
      <button type="button" onClick={() => onCancelar(row)} className="rounded-lg p-2 text-slate-500 hover:bg-red-50 hover:text-red-600" title="Cancelar título">
        <Ban className="h-4 w-4" aria-label="Cancelar título" />
      </button>
    </>
  );
}

export function ContasReceberPage() {
  const qc = useQueryClient();
  const { toast } = useFeedback();
  const [baixa, setBaixa] = useState<Row | null>(null);
  const [cancelar, setCancelar] = useState<Row | null>(null);
  const [mensalidades, setMensalidades] = useState(false);
  const recarregar = () => qc.invalidateQueries({ queryKey: ['/contas-receber'] });

  const sincronizar = useMutation({
    mutationFn: () => http.post<{ consultados: number; pagos: number; erros: number }>('/financeiro/sincronizar'),
    onSuccess: (r) => { toast(`${r.consultados} título(s) consultado(s), ${r.pagos} baixa(s)${r.erros ? `, ${r.erros} erro(s) de comunicação` : ''}.`, r.erros ? 'error' : 'success'); recarregar(); },
    onError: (e) => toast(erroMsg(e), 'error'),
  });

  const config: CrudConfig = {
    title: 'Contas a receber',
    subtitle: 'Mensalidades, matrículas e rematrículas. Pagamentos por boleto/cartão são baixados automaticamente.',
    endpoint: '/contas-receber',
    singular: 'Título',
    filters: [
      { param: 'situacaoId', label: 'Situação', options: [{ id: 1, nome: 'A receber' }, { id: 2, nome: 'Recebido' }, { id: 4, nome: 'Cancelado' }] },
      { param: 'categoriaId', label: 'Categoria', options: [{ id: 1, nome: 'Mensalidade' }, { id: 2, nome: 'Matrícula' }, { id: 3, nome: 'Rematrícula' }] },
    ],
    columns: [
      { key: 'aluno', label: 'Aluno', render: (r) => <><p className="font-medium text-slate-900">{String(r.aluno ?? '—')}</p><p className="text-xs text-slate-500">{String(r.categoria ?? '')}</p></> },
      { key: 'dataVencimento', label: 'Vencimento', render: (r) => <span className={cx(Number(r.vencido) > 0 && 'font-medium text-red-600')}>{fmt.date(r.dataVencimento)}</span> },
      { key: 'valor', label: 'Valor', render: (r) => fmt.money(r.valor), className: 'text-right' },
      { key: 'situacao', label: 'Situação', render: (r) => <span className={cx('rounded-full px-2 py-0.5 text-xs font-medium', SITUACAO[Number(r.listaSituacaoCrId)])}>{Number(r.vencido) ? 'Vencido' : String(r.situacao ?? '')}</span> },
      { key: 'dataPagamento', label: 'Pagamento', render: (r) => fmt.date(r.dataPagamento) },
    ],
    fields: [
      { name: 'usuarioId', label: 'Aluno', type: 'select', lista: 'alunos', required: true, wide: true },
      { name: 'listaCategoriaCrId', label: 'Categoria', type: 'select', options: [{ id: 1, nome: 'Mensalidade' }, { id: 2, nome: 'Matrícula' }, { id: 3, nome: 'Rematrícula' }] },
      { name: 'valor', label: 'Valor (R$)', type: 'decimal', required: true },
      { name: 'dataVencimento', label: 'Vencimento', type: 'date', required: true },
      { name: 'observacao', label: 'Observação', type: 'textarea', wide: true },
    ],
    defaults: () => ({ listaCategoriaCrId: 1 }),
    canDelete: () => false, // títulos não se apagam: cancelam (fica o histórico)
    rowActions: (row, reload) => <AcoesTitulo row={row} reload={reload} onBaixa={setBaixa} onCancelar={setCancelar} />,
  };

  return (
    <>
      <CrudPage config={config}>
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="secondary" onClick={() => setMensalidades(true)}><CalendarPlus className="h-4 w-4" /> Gerar mensalidades</Button>
          <Button variant="secondary" loading={sincronizar.isPending} onClick={() => sincronizar.mutate()}><RefreshCw className="h-4 w-4" /> Atualizar pagamentos</Button>
          <Button variant="secondary" onClick={() => abrirArquivo(`/financeiro/receber/exportar?de=${new Date().getFullYear()}-01-01&ate=${new Date().getFullYear()}-12-31`, 'contas-a-receber.csv')}>
            <Download className="h-4 w-4" /> Planilha do ano
          </Button>
        </div>
      </CrudPage>
      {baixa && <BaixaModal titulo={baixa} onClose={() => { setBaixa(null); recarregar(); }} />}
      {cancelar && <CancelarModal titulo={cancelar} onClose={() => { setCancelar(null); recarregar(); }} />}
      {mensalidades && <MensalidadesModal onClose={() => { setMensalidades(false); recarregar(); }} />}
    </>
  );
}

function BaixaModal({ titulo, onClose }: { titulo: Row; onClose: () => void }) {
  const { toast } = useFeedback();
  const [data, setData] = useState(new Date().toISOString().slice(0, 10));
  const [obs, setObs] = useState('');
  const m = useMutation({
    mutationFn: () => http.post(`/contas-receber/${titulo.id}/baixa`, { dataPagamento: data, observacao: obs || undefined }),
    onSuccess: () => { toast('Pagamento registrado.'); onClose(); },
    onError: (e) => toast(erroMsg(e), 'error'),
  });
  return (
    <Modal open title="Baixa manual" onClose={onClose}
      footer={<><Button variant="secondary" onClick={onClose}>Cancelar</Button><Button loading={m.isPending} onClick={() => m.mutate()}>Registrar pagamento</Button></>}>
      <div className="space-y-4">
        <p className="text-sm text-slate-600">{String(titulo.aluno)} · {fmt.money(titulo.valor)} · vence {fmt.date(titulo.dataVencimento)}</p>
        <Input label="Data do pagamento" type="date" value={data} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setData(e.target.value)} />
        <Textarea label="Observação (ex.: pago em dinheiro na secretaria)" value={obs} onChange={(e) => setObs(e.target.value)} />
        <p className="text-xs text-slate-500">Se for matrícula ou rematrícula, o aluno é reativado automaticamente.</p>
      </div>
    </Modal>
  );
}

function CancelarModal({ titulo, onClose }: { titulo: Row; onClose: () => void }) {
  const { toast } = useFeedback();
  const [motivo, setMotivo] = useState('');
  const m = useMutation({
    mutationFn: () => http.post(`/contas-receber/${titulo.id}/cancelar`, { motivo }),
    onSuccess: () => { toast('Título cancelado.'); onClose(); },
    onError: (e) => toast(erroMsg(e), 'error'),
  });
  return (
    <Modal open title="Cancelar título" onClose={onClose}
      footer={<><Button variant="secondary" onClick={onClose}>Voltar</Button><Button variant="danger" disabled={!motivo.trim()} loading={m.isPending} onClick={() => m.mutate()}>Cancelar título</Button></>}>
      <div className="space-y-3">
        <p className="text-sm text-slate-600">{String(titulo.aluno)} · {fmt.money(titulo.valor)}</p>
        <Textarea label="Motivo *" value={motivo} onChange={(e) => setMotivo(e.target.value)} />
        <p className="text-xs text-slate-500">O título fica no histórico como cancelado e deixa de contar como pendência.</p>
      </div>
    </Modal>
  );
}

interface Gerado { aluno: string; curso: string; vencimento: string; valor: number }

function MensalidadesModal({ onClose }: { onClose: () => void }) {
  const { toast } = useFeedback();
  const [comp, setComp] = useState(competenciaAtual());
  const [previa, setPrevia] = useState<{ gerados: Gerado[]; ignorados: number; total: number } | null>(null);
  const simular = useMutation({
    mutationFn: () => http.post<{ gerados: Gerado[]; ignorados: number; total: number }>('/financeiro/mensalidades', { competencia: comp, simular: true }),
    onSuccess: setPrevia,
    onError: (e) => toast(erroMsg(e), 'error'),
  });
  const gerar = useMutation({
    mutationFn: () => http.post<{ gerados: Gerado[] }>('/financeiro/mensalidades', { competencia: comp }),
    onSuccess: (r) => { toast(`${r.gerados.length} mensalidade(s) gerada(s).`); onClose(); },
    onError: (e) => toast(erroMsg(e), 'error'),
  });
  return (
    <Modal open title="Gerar mensalidades" onClose={onClose} size="lg"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Fechar</Button>
        {previa ? <Button disabled={!previa.gerados.length} loading={gerar.isPending} onClick={() => gerar.mutate()}>Gerar {previa.gerados.length}</Button>
          : <Button loading={simular.isPending} onClick={() => simular.mutate()}>Ver prévia</Button>}
      </>}>
      <div className="space-y-4">
        <Input label="Competência (mês)" type="month" value={comp} onChange={(e) => { setComp(e.target.value); setPrevia(null); }} />
        <p className="text-xs text-slate-500">Uma mensalidade por aluno ativo (valor do curso − desconto), no dia de vencimento de cada aluno. Respeita a periodicidade do curso e não duplica.</p>
        {previa && (
          previa.gerados.length === 0 ? <Alert kind="success">Nada a gerar nesta competência ({previa.ignorados} aluno(s) já cobrados ou fora da periodicidade).</Alert> : (
            <div className="max-h-72 overflow-y-auto rounded-lg border border-slate-200">
              <table className="min-w-full text-sm">
                <thead className="sticky top-0 bg-slate-50"><tr>{['Aluno', 'Curso', 'Vencimento', 'Valor'].map((h) => <th key={h} className="px-3 py-2 text-left font-semibold text-slate-600">{h}</th>)}</tr></thead>
                <tbody className="divide-y divide-slate-100">
                  {previa.gerados.map((g, i) => <tr key={i}><td className="px-3 py-1.5">{g.aluno}</td><td className="px-3 py-1.5">{g.curso}</td><td className="px-3 py-1.5">{fmt.date(g.vencimento)}</td><td className="px-3 py-1.5">{fmt.money(g.valor)}</td></tr>)}
                </tbody>
              </table>
              <p className="border-t border-slate-200 px-3 py-2 text-right text-sm font-medium">Total {fmt.money(previa.total)}</p>
            </div>
          )
        )}
      </div>
    </Modal>
  );
}

/* ===================== Contas a pagar / fixas ===================== */

const contasPagar: CrudConfig = {
  title: 'Contas a pagar',
  endpoint: '/contas-pagar',
  singular: 'Conta',
  filters: [{ param: 'situacaoId', label: 'Situação', options: [{ id: 1, nome: 'A pagar' }, { id: 2, nome: 'Pago' }] }],
  columns: [
    { key: 'descricao', label: 'Descrição', render: (r) => <><p className="font-medium text-slate-900">{String(r.descricao)}</p><p className="text-xs text-slate-500">{String(r.categoria ?? '')}</p></> },
    { key: 'dataVencimento', label: 'Vencimento', render: (r) => fmt.date(r.dataVencimento) },
    { key: 'valor', label: 'Valor', render: (r) => fmt.money(r.valor), className: 'text-right' },
    { key: 'dataPagamento', label: 'Pago em', render: (r) => fmt.date(r.dataPagamento) },
  ],
  fields: [
    { name: 'descricao', label: 'Descrição', required: true, wide: true },
    { name: 'listaCategoriaCpId', label: 'Categoria', type: 'select', options: [{ id: 1, nome: 'Aluguel' }, { id: 2, nome: 'Salários' }, { id: 3, nome: 'Outros' }] },
    { name: 'listaSituacaoCpId', label: 'Situação', type: 'select', options: [{ id: 1, nome: 'A pagar' }, { id: 2, nome: 'Pago' }] },
    { name: 'valor', label: 'Valor (R$)', type: 'decimal', required: true },
    { name: 'dataVencimento', label: 'Vencimento', type: 'date', required: true },
    { name: 'dataPagamento', label: 'Data do pagamento', type: 'date' },
  ],
  defaults: () => ({ listaSituacaoCpId: 1 }),
};
export const ContasPagarPage = () => <CrudPage config={contasPagar} />;

export function ContasFixasPage() {
  const { toast } = useFeedback();
  const [comp, setComp] = useState(competenciaAtual());
  const gerar = useMutation({
    mutationFn: () => http.post<{ geradas: number }>('/financeiro/contas-fixas/gerar', { competencia: comp }),
    onSuccess: (r) => toast(r.geradas ? `${r.geradas} conta(s) a pagar gerada(s).` : 'Todas as contas desta competência já existiam.'),
    onError: (e) => toast(erroMsg(e), 'error'),
  });
  const config: CrudConfig = {
    title: 'Contas fixas',
    subtitle: 'Despesas recorrentes. Gere as contas a pagar de cada mês com um clique.',
    endpoint: '/contas-fixas',
    singular: 'Conta fixa',
    columns: [
      { key: 'descricao', label: 'Descrição' },
      { key: 'categoria', label: 'Categoria', render: (r) => String(r.categoria ?? '—') },
      { key: 'diaVencimento', label: 'Dia', className: 'text-right' },
      { key: 'valor', label: 'Valor', render: (r) => fmt.money(r.valor), className: 'text-right' },
    ],
    fields: [
      { name: 'descricao', label: 'Descrição', required: true, wide: true },
      { name: 'listaCategoriaCpId', label: 'Categoria', type: 'select', options: [{ id: 1, nome: 'Aluguel' }, { id: 2, nome: 'Salários' }, { id: 3, nome: 'Outros' }] },
      { name: 'diaVencimento', label: 'Dia de vencimento', type: 'number', required: true },
      { name: 'valor', label: 'Valor (R$)', type: 'decimal', required: true },
    ],
  };
  return (
    <CrudPage config={config}>
      <Card className="flex flex-wrap items-end gap-3">
        <Input label="Competência" type="month" value={comp} onChange={(e) => setComp(e.target.value)} />
        <Button loading={gerar.isPending} onClick={() => gerar.mutate()}><CalendarPlus className="h-4 w-4" /> Gerar contas a pagar do mês</Button>
      </Card>
    </CrudPage>
  );
}

/* ===================== Contas de recebimento (gateways) ===================== */

function CredencialModal({ conta, onClose }: { conta: Row; onClose: () => void }) {
  const { toast } = useFeedback();
  const [token, setToken] = useState('');
  const webhook = useQuery({
    queryKey: ['webhook', conta.id],
    queryFn: () => http.get<{ url: string }>(`/contas-bancarias/${conta.id}/webhook`),
    enabled: conta.banco === 'MercadoPago',
  });
  const salvar = useMutation({
    mutationFn: () => http.put(`/contas-bancarias/${conta.id}/token`, { token }),
    onSuccess: () => { toast('Credencial salva.'); onClose(); },
    onError: (e) => toast(erroMsg(e), 'error'),
  });
  return (
    <Modal open title={`Credencial — ${conta.banco}`} onClose={onClose}
      footer={<><Button variant="secondary" onClick={onClose}>Fechar</Button><Button disabled={!token.trim()} loading={salvar.isPending} onClick={() => salvar.mutate()}>Salvar credencial</Button></>}>
      <div className="space-y-4">
        <Alert kind="success">Por segurança, a credencial atual nunca é exibida. Informe uma nova para substituí-la.</Alert>
        <Input label={conta.banco === 'MercadoPago' ? 'Access token de produção' : 'Token da conta'} type="password" autoComplete="off" value={token} onChange={(e) => setToken(e.target.value)} />
        {conta.banco === 'MercadoPago' && webhook.data && (
          <div>
            <p className="mb-1 text-sm font-medium text-slate-700">URL de notificação (cadastre no painel do MercadoPago)</p>
            <div className="flex items-center gap-2 rounded-lg bg-slate-50 p-2 text-xs">
              <Link2 className="h-4 w-4 shrink-0 text-slate-400" />
              <code className="flex-1 break-all">{webhook.data.url}</code>
              <button type="button" className="text-primary hover:underline" onClick={() => { navigator.clipboard?.writeText(webhook.data!.url); toast('Copiado.'); }}>Copiar</button>
            </div>
          </div>
        )}
      </div>
    </Modal>
  );
}

export function ContasBancariasPage() {
  const [cred, setCred] = useState<Row | null>(null);
  const config: CrudConfig = {
    title: 'Contas de recebimento',
    subtitle: 'Provedores de pagamento da instituição. Boleto: BoletoCloud ou MercadoPago. Cartão: Vindi, PagSeguro ou MercadoPago.',
    endpoint: '/contas-bancarias',
    singular: 'Conta',
    searchable: false,
    columns: [
      { key: 'banco', label: 'Provedor', render: (r) => <span className="font-medium text-slate-900">{String(r.banco)}</span> },
      { key: 'titular', label: 'Titular', render: (r) => String(r.titular ?? '—') },
      { key: 'tokenConfigurado', label: 'Credencial', render: (r) => (Number(r.tokenConfigurado) ? <span className="text-emerald-700">Configurada</span> : <span className="text-amber-600">Pendente</span>) },
      { key: 'autorizar', label: 'Situação', render: (r) => (r.autorizar ? 'Ativa' : <span className="text-slate-400">Inativa</span>) },
    ],
    fields: [
      { name: 'banco', label: 'Provedor', type: 'select', required: true,
        options: ['BoletoCloud', 'MercadoPago', 'Vindi', 'PagSeguro'].map((g) => ({ id: g, nome: g })) },
      { name: 'titular', label: 'Titular' },
      { name: 'cnpj', label: 'CNPJ' },
      { name: 'pagseguroEmail', label: 'E-mail da conta PagSeguro', hidden: (f) => f.banco !== 'PagSeguro' },
      { name: 'instrucoes', label: 'Instruções no boleto', type: 'textarea', wide: true },
      { name: 'autorizar', label: 'Conta ativa', type: 'checkbox' },
    ],
    defaults: () => ({ autorizar: true }),
    rowActions: (row) => (
      <button type="button" onClick={() => setCred(row)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-primary" title="Credencial do provedor">
        <KeyRound className="h-4 w-4" aria-label="Credencial do provedor" />
      </button>
    ),
  };
  return (
    <>
      <CrudPage config={config} />
      {cred && <CredencialModal conta={cred} onClose={() => setCred(null)} />}
    </>
  );
}

/* ===================== Relatório financeiro ===================== */

interface Resumo {
  receber: { recebido: number; aReceber: number; vencido: number; qtdVencidos: number };
  pagar: { pago: number; aPagar: number };
  mensal: { mes: string; recebido: number; aberto: number }[];
}

export function RelatorioFinanceiroPage() {
  const ano = new Date().getFullYear();
  const [de, setDe] = useState(`${ano}-01-01`);
  const [ate, setAte] = useState(`${ano}-12-31`);
  const r = useQuery({ queryKey: ['resumo', de, ate], queryFn: () => http.get<Resumo>(`/financeiro/resumo?de=${de}&ate=${ate}`) });
  const max = Math.max(1, ...(r.data?.mensal ?? []).map((m) => m.recebido + m.aberto));
  const tile = (rotulo: string, valor: number, cls = 'text-slate-900', extra?: string) => (
    <Card><p className="text-sm text-slate-500">{rotulo}</p><p className={cx('mt-1 text-2xl font-semibold tabular-nums', cls)}>{fmt.money(valor)}</p>{extra && <p className="text-xs text-slate-500">{extra}</p>}</Card>
  );
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h1 className="text-2xl font-semibold text-slate-900">Relatório financeiro</h1>
        <div className="flex flex-wrap items-end gap-2">
          <Input label="De" type="date" value={de} onChange={(e) => setDe(e.target.value)} />
          <Input label="Até" type="date" value={ate} onChange={(e) => setAte(e.target.value)} />
          <Button variant="secondary" onClick={() => abrirArquivo(`/financeiro/receber/exportar?de=${de}&ate=${ate}`, 'contas-a-receber.csv')}><Download className="h-4 w-4" /> Planilha</Button>
        </div>
      </div>
      {r.isError && <Alert>{(r.error as Error).message}</Alert>}
      {r.data && (
        <>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {tile('Recebido', r.data.receber.recebido, 'text-emerald-700')}
            {tile('A receber (no prazo)', r.data.receber.aReceber)}
            {tile('Vencido', r.data.receber.vencido, 'text-red-600', `${r.data.receber.qtdVencidos} título(s)`)}
            {tile('Saldo (recebido − pago)', r.data.receber.recebido - r.data.pagar.pago, 'text-slate-900', `Despesas pagas: ${fmt.money(r.data.pagar.pago)}`)}
          </div>
          <Card title="Por mês de vencimento">
            {r.data.mensal.length === 0 ? <p className="text-sm text-slate-500">Sem títulos no período.</p> : (
              <ul className="space-y-2" aria-label="Recebido e em aberto por mês">
                {r.data.mensal.map((m) => (
                  <li key={m.mes} className="grid grid-cols-[64px_1fr_auto] items-center gap-3 text-sm">
                    <span className="text-slate-500">{m.mes.slice(5)}/{m.mes.slice(2, 4)}</span>
                    <span className="flex h-3 overflow-hidden rounded-full bg-slate-100" title={`Recebido ${fmt.money(m.recebido)} · em aberto ${fmt.money(m.aberto)}`}>
                      <span className="bg-emerald-500" style={{ width: `${(m.recebido / max) * 100}%` }} />
                      <span className="bg-amber-400" style={{ width: `${(m.aberto / max) * 100}%` }} />
                    </span>
                    <span className="tabular-nums text-slate-700">{fmt.money(m.recebido + m.aberto)}</span>
                  </li>
                ))}
              </ul>
            )}
            <p className="mt-3 flex gap-4 text-xs text-slate-500"><span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-emerald-500" />Recebido</span><span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-amber-400" />Em aberto</span></p>
          </Card>
        </>
      )}
    </div>
  );
}
