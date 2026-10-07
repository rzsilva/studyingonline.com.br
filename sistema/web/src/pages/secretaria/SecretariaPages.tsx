import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Download, Eye, Mail, RefreshCw, Search, X } from 'lucide-react';
import { ApiError, abrirArquivo, apiWithMeta, http } from '../../api/client';
import { Alert, Button, Card, cx } from '../../components/ui';
import { FilterSelect, Textarea, useLista } from '../../components/form';
import { Modal, useFeedback } from '../../components/overlay';
import { CrudPage, fmt, type CrudConfig, type Row } from '../../components/CrudPage';

/* ===================== Inscrições ===================== */

interface InscricaoItem { id: number; status: number; data: string | null; formaPagamento: string; nome: string; email: string; cpf: string | null; matricula: string | null; documentos: number; cursos: string | null }
interface InscricaoDetalhe {
  id: number; status: number; justificativaReprovacao: string | null; data: string | null; formaPagamento: number;
  aluno: { id: number; nome: string; email: string; cpf: string | null; celular: string | null; telefone: string | null; dataNascimento: string | null; matricula: string | null; endereco: string; inativo: boolean };
  cursos: { id: number; nome: string }[];
  questionario: Record<string, string | boolean>;
  documentos: { campo: string; rotulo: string; enviado: boolean }[];
}

export const STATUS_INSCRICAO: Record<number, { nome: string; cls: string }> = {
  1: { nome: 'Pendente', cls: 'bg-amber-50 text-amber-700' },
  2: { nome: 'Aprovada', cls: 'bg-emerald-50 text-emerald-700' },
  3: { nome: 'Reprovada', cls: 'bg-red-50 text-red-700' },
};
const rotuloCampo = (k: string) => k.replace(/([A-Z])/g, ' $1').replace(/^./, (c) => c.toUpperCase());

export function InscricoesPage() {
  const [status, setStatus] = useState('1');
  const [cursoId, setCursoId] = useState('');
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [aberta, setAberta] = useState<number | null>(null);
  const cursos = useLista('cursos');
  const qs = new URLSearchParams({ page: String(page), ...(status ? { status } : {}), ...(cursoId ? { cursoId } : {}), ...(q ? { q } : {}) }).toString();
  const lista = useQuery({ queryKey: ['inscricoes', qs], queryFn: () => apiWithMeta<InscricaoItem[]>(`/inscricoes?${qs}`), placeholderData: keepPreviousData });
  const total = lista.data?.meta?.total ?? 0;

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Inscrições</h1>
        <p className="mt-1 text-sm text-slate-500">Analise documentos e aprove ou reprove os candidatos. O candidato é avisado por e-mail.</p>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex gap-1 rounded-lg bg-slate-100 p-1" role="tablist">
          {[['1', 'Pendentes'], ['2', 'Aprovadas'], ['3', 'Reprovadas'], ['', 'Todas']].map(([v, l]) => (
            <button key={v} role="tab" aria-selected={status === v} type="button" onClick={() => { setStatus(v); setPage(1); }}
              className={cx('rounded-md px-3 py-1.5 text-sm font-medium', status === v ? 'bg-white text-slate-900 shadow' : 'text-slate-600')}>{l}</button>
          ))}
        </div>
        <div className="relative min-w-[200px] flex-1 sm:max-w-xs">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input type="search" aria-label="Buscar candidato" placeholder="Nome, e-mail, CPF…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }}
            className="w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/15" />
        </div>
        <FilterSelect label="Curso" placeholder="Todos os cursos" value={cursoId} onChange={(v) => { setCursoId(v); setPage(1); }} options={cursos.data ?? []} />
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50"><tr>
            {['Candidato', 'Curso', 'Data', 'Documentos', 'Situação', ''].map((h) => <th key={h} className="px-4 py-3 text-left font-semibold text-slate-600">{h}</th>)}
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {lista.data?.data.length === 0 && <tr><td colSpan={6} className="p-8 text-center text-slate-500">Nenhuma inscrição.</td></tr>}
            {lista.data?.data.map((i) => (
              <tr key={i.id} className="hover:bg-slate-50">
                <td className="px-4 py-3"><p className="font-medium text-slate-900">{i.nome}</p><p className="text-xs text-slate-500">{i.email} · {i.cpf ?? 'sem CPF'}</p></td>
                <td className="px-4 py-3">{i.cursos ?? '—'}</td>
                <td className="px-4 py-3">{fmt.date(i.data)}</td>
                <td className="px-4 py-3">{i.documentos}/7</td>
                <td className="px-4 py-3"><span className={cx('rounded-full px-2 py-0.5 text-xs font-medium', STATUS_INSCRICAO[i.status]?.cls)}>{STATUS_INSCRICAO[i.status]?.nome}</span></td>
                <td className="px-4 py-2 text-right">
                  <button type="button" onClick={() => setAberta(i.id)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-primary" title="Analisar"><Eye className="h-4 w-4" aria-label="Analisar" /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {total > 25 && (
        <div className="flex justify-end gap-2">
          <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Anterior</Button>
          <Button variant="secondary" disabled={page * 25 >= total} onClick={() => setPage((p) => p + 1)}>Próxima</Button>
        </div>
      )}
      {aberta && <InscricaoModal id={aberta} onClose={() => setAberta(null)} />}
    </div>
  );
}

function InscricaoModal({ id, onClose }: { id: number; onClose: () => void }) {
  const qc = useQueryClient();
  const { toast } = useFeedback();
  const d = useQuery({ queryKey: ['inscricao', id], queryFn: () => http.get<InscricaoDetalhe>(`/inscricoes/${id}`) });
  const [reprovando, setReprovando] = useState(false);
  const [motivo, setMotivo] = useState('');
  const decidir = useMutation({
    mutationFn: (status: number) => http.put(`/inscricoes/${id}/status`, { status, justificativa: motivo || undefined }),
    onSuccess: (_, status) => {
      toast(status === 2 ? 'Inscrição aprovada. O candidato foi avisado.' : 'Inscrição reprovada. O candidato foi avisado.');
      qc.invalidateQueries({ queryKey: ['inscricoes'] });
      onClose();
    },
    onError: (e) => toast(e instanceof ApiError ? Object.values(e.fields)[0] ?? e.message : 'Falha.', 'error'),
  });
  const baixar = async (campo: string, rotulo: string) => {
    try {
      await abrirArquivo(`/inscricoes/${id}/documentos/${campo.toLowerCase()}`, rotulo);
    } catch (e) {
      toast(e instanceof ApiError ? e.message : 'Falha ao baixar.', 'error');
    }
  };

  const i = d.data;
  return (
    <Modal open title="Análise da inscrição" onClose={onClose} size="xl"
      footer={i?.status === 1 ? (reprovando ? (
        <>
          <Button variant="secondary" onClick={() => setReprovando(false)}>Voltar</Button>
          <Button variant="danger" disabled={!motivo.trim()} loading={decidir.isPending} onClick={() => decidir.mutate(3)}>Confirmar reprovação</Button>
        </>
      ) : (
        <>
          <Button variant="secondary" onClick={() => setReprovando(true)}><X className="h-4 w-4" /> Reprovar</Button>
          <Button loading={decidir.isPending} onClick={() => decidir.mutate(2)}><Check className="h-4 w-4" /> Aprovar</Button>
        </>
      )) : <Button variant="secondary" onClick={onClose}>Fechar</Button>}>
      {d.isLoading && <p className="text-sm text-slate-400">Carregando…</p>}
      {d.isError && <Alert>{(d.error as Error).message}</Alert>}
      {i && (
        <div className="space-y-5">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="text-lg font-semibold text-slate-900">{i.aluno.nome}</p>
              <p className="text-sm text-slate-500">Matrícula {i.aluno.matricula} · inscrito em {fmt.date(i.data)} · {i.cursos.map((c) => c.nome).join(', ')}</p>
            </div>
            <span className={cx('rounded-full px-2.5 py-1 text-xs font-medium', STATUS_INSCRICAO[i.status]?.cls)}>{STATUS_INSCRICAO[i.status]?.nome}</span>
          </div>
          {i.justificativaReprovacao && <Alert>Motivo da reprovação: {i.justificativaReprovacao}</Alert>}
          <div className="grid gap-4 md:grid-cols-2">
            <Card title="Dados">
              <dl className="space-y-1.5 text-sm">
                {([['E-mail', i.aluno.email], ['CPF', i.aluno.cpf], ['Celular', i.aluno.celular], ['Nascimento', fmt.date(i.aluno.dataNascimento)],
                  ['Endereço', i.aluno.endereco || '—'], ['Pagamento', i.formaPagamento === 2 ? 'Cartão' : 'Boleto']] as const).map(([k, v]) => (
                  <div key={k} className="flex gap-3"><dt className="w-28 shrink-0 text-slate-500">{k}</dt><dd className="text-slate-800">{v ?? '—'}</dd></div>
                ))}
              </dl>
            </Card>
            <Card title="Documentos">
              <ul className="space-y-1.5 text-sm">
                {i.documentos.map((doc) => (
                  <li key={doc.campo} className="flex items-center justify-between gap-2">
                    <span className={doc.enviado ? 'text-slate-800' : 'text-slate-400'}>{doc.rotulo}</span>
                    {doc.enviado ? (
                      <button type="button" onClick={() => baixar(doc.campo, doc.rotulo)} className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                        <Download className="h-3.5 w-3.5" /> Baixar
                      </button>
                    ) : <span className="text-xs text-slate-400">não enviado</span>}
                  </li>
                ))}
              </ul>
            </Card>
          </div>
          {Object.keys(i.questionario).length > 0 && (
            <Card title="Questionário">
              <dl className="grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                {Object.entries(i.questionario).map(([k, v]) => (
                  <div key={k}><dt className="text-xs text-slate-500">{rotuloCampo(k)}</dt><dd className="text-slate-800">{typeof v === 'boolean' ? (v ? 'Sim' : 'Não') : v}</dd></div>
                ))}
              </dl>
            </Card>
          )}
          {reprovando && <Textarea label="Motivo da reprovação (enviado ao candidato) *" rows={3} value={motivo} onChange={(e) => setMotivo(e.target.value)} />}
        </div>
      )}
    </Modal>
  );
}

/* ===================== Usuários / Professores ===================== */

function ConviteButton({ row }: { row: Row }) {
  const { toast, confirm } = useFeedback();
  const enviar = async () => {
    if (!(await confirm(`Enviar a ${row.email} um link para criar a senha de acesso?`))) return;
    try {
      const r = await http.post<{ message: string }>(`/usuarios/${row.id}/convite`);
      toast(r.message);
    } catch (e) {
      toast(e instanceof ApiError ? e.message : 'Falha ao enviar.', 'error');
    }
  };
  return (
    <button type="button" onClick={enviar} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-primary" title="Enviar convite de acesso">
      <Mail className="h-4 w-4" aria-label="Enviar convite de acesso" />
    </button>
  );
}

const usuarios: CrudConfig = {
  title: 'Usuários',
  subtitle: 'Alunos, professores e administradores. Senhas não são definidas aqui: envie um convite (ícone de e-mail).',
  endpoint: '/usuarios',
  singular: 'Usuário',
  filters: [{ param: 'perfilId', label: 'Perfil', lista: 'perfis' }, { param: 'inativo', label: 'Situação', options: [{ id: 0, nome: 'Ativos' }, { id: 1, nome: 'Inativos' }] }],
  columns: [
    { key: 'nome', label: 'Nome', render: (r) => <><p className="font-medium text-slate-900">{String(r.nome)}</p><p className="text-xs text-slate-500">{String(r.email)}</p></> },
    { key: 'perfil', label: 'Perfil' },
    { key: 'matricula', label: 'Matrícula', render: (r) => String(r.matricula ?? '—') },
    { key: 'acessoDefinido', label: 'Acesso', render: (r) => (Number(r.acessoDefinido) ? 'Senha definida' : <span className="text-amber-600">Sem senha</span>) },
    { key: 'inativo', label: 'Situação', render: (r) => (r.inativo ? <span className="text-slate-400">Inativo</span> : <span className="text-emerald-700">Ativo</span>) },
  ],
  fields: [
    { name: 'nome', label: 'Nome completo', required: true, wide: true },
    { name: 'email', label: 'E-mail (login)', type: 'text', required: true },
    { name: 'cpf', label: 'CPF' },
    { name: 'listaPerfilId', label: 'Perfil', type: 'select', lista: 'perfis', required: true },
    { name: 'listaUnidadeId', label: 'Unidade', type: 'select', lista: 'unidades', required: true },
    { name: 'listaTurmaId', label: 'Turma', type: 'select', lista: 'turmas' },
    { name: 'listaEstadoCivilId', label: 'Estado civil', type: 'select', lista: 'estados-civis', required: true },
    { name: 'matricula', label: 'Matrícula' },
    { name: 'dataNascimento', label: 'Nascimento', type: 'date' },
    { name: 'sexo', label: 'Sexo', type: 'select', options: [{ id: 'M', nome: 'Masculino' }, { id: 'F', nome: 'Feminino' }] },
    { name: 'rg', label: 'RG' },
    { name: 'celular', label: 'Celular' },
    { name: 'telefone', label: 'Telefone' },
    { name: 'cep', label: 'CEP' },
    { name: 'rua', label: 'Rua', wide: true },
    { name: 'numero', label: 'Número', type: 'number' },
    { name: 'bairro', label: 'Bairro' },
    { name: 'cidade', label: 'Cidade' },
    { name: 'uf', label: 'UF' },
    { name: 'desconto', label: 'Desconto na mensalidade (R$)', type: 'decimal' },
    { name: 'diaVencimento', label: 'Dia de vencimento', type: 'number' },
    { name: 'inativo', label: 'Usuário inativo', type: 'checkbox' },
  ],
  defaults: () => ({ listaPerfilId: 3, diaVencimento: 10, listaEstadoCivilId: 1 }),
  rowActions: (row) => <ConviteButton row={row} />,
  formSize: 'xl',
};

export function UsuariosPage() {
  const { toast, confirm } = useFeedback();
  const qc = useQueryClient();
  const rotina = async () => {
    if (!(await confirm('Inativar os alunos cujo tempo de curso terminou? (Rotina automática do legado.)'))) return;
    try {
      const r = await http.post<{ inativados: number }>('/rotinas/inatividade');
      toast(`${r.inativados} aluno(s) inativado(s).`);
      qc.invalidateQueries({ queryKey: ['/usuarios'] });
    } catch (e) {
      toast(e instanceof ApiError ? e.message : 'Falha.', 'error');
    }
  };
  return (
    <CrudPage config={usuarios}>
      <div className="flex justify-end">
        <Button variant="secondary" onClick={rotina}><RefreshCw className="h-4 w-4" /> Atualizar inatividade</Button>
      </div>
    </CrudPage>
  );
}

const professores: CrudConfig = {
  title: 'Professores',
  subtitle: 'Cadastro usado nos módulos dos cursos. Para o professor acessar o sistema, cadastre-o também em Usuários (perfil Professor).',
  endpoint: '/professores',
  singular: 'Professor',
  columns: [
    { key: 'professor', label: 'Professor' },
    { key: 'email', label: 'E-mail', render: (r) => String(r.email ?? '—') },
    { key: 'telefone', label: 'Telefone', render: (r) => String(r.telefone ?? '—') },
  ],
  fields: [
    { name: 'professor', label: 'Nome', required: true, wide: true },
    { name: 'email', label: 'E-mail' },
    { name: 'telefone', label: 'Telefone' },
    { name: 'cpf', label: 'CPF' },
    { name: 'rg', label: 'RG' },
    { name: 'dataNascimento', label: 'Nascimento', type: 'date' },
    { name: 'estadoCivil', label: 'Estado civil' },
    { name: 'nomeMae', label: 'Nome da mãe', wide: true },
    { name: 'url', label: 'URL da foto', type: 'url', wide: true },
    { name: 'descricao', label: 'Mini currículo', type: 'textarea', wide: true },
    { name: 'banco', label: 'Banco' },
    { name: 'agencia', label: 'Agência' },
    { name: 'conta', label: 'Conta' },
    { name: 'observacao', label: 'Observações', type: 'textarea', wide: true },
  ],
  formSize: 'xl',
};

export const ProfessoresPage = () => <CrudPage config={professores} />;
