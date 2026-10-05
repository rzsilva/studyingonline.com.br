import { useRef, useState } from 'react';
import { Copy, Download, ListChecks, Upload } from 'lucide-react';
import { ApiError, download, upload } from '../../api/client';
import { useAuth } from '../../auth/AuthProvider';
import { CrudPage, fmt, type CrudConfig, type FieldDef, type Row } from '../../components/CrudPage';
import { useFeedback } from '../../components/overlay';
import { Perfil } from '../../lib/types';
import { QuestoesModal, CopiarProvaModal } from './ProvaModals';

const staff = (u: { perfilId: number }) => u.perfilId === Perfil.Administrador || u.perfilId === Perfil.Professor;
const cursoFilter = { param: 'cursoId', label: 'Curso', lista: 'cursos' };
const moduloFilter = {
  param: 'moduloId',
  label: 'Módulo',
  lista: 'modulos',
  listaParams: (f: Record<string, string>) => (f.cursoId ? `?cursoId=${f.cursoId}` : null),
};
/** Curso (só filtra o select) + módulo. O campo cursoId é ignorado pela API nesses recursos. */
const cursoModulo: FieldDef[] = [
  { name: 'cursoId', label: 'Curso', type: 'select', lista: 'cursos', required: true },
  { name: 'disciplinaId', label: 'Módulo', type: 'select', lista: 'modulos', required: true,
    listaParams: (form) => (form.cursoId ? `?cursoId=${form.cursoId}` : null) },
];
const badge = (on: boolean, yes = 'Ativo', no = 'Inativo') => (
  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${on ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{on ? yes : no}</span>
);

const cursos: CrudConfig = {
  title: 'Cursos',
  subtitle: 'Cursos oferecidos pela instituição.',
  endpoint: '/cursos',
  singular: 'Curso',
  columns: [
    { key: 'nome', label: 'Curso', render: (r) => <><p className="font-medium text-slate-900">{String(r.nome)}</p><p className="text-xs text-slate-500">{String(r.subtitulo ?? '')}</p></> },
    { key: 'tipoCurso', label: 'Tipo' },
    { key: 'totalAlunos', label: 'Alunos', className: 'text-right' },
    { key: 'valor', label: 'Mensalidade', render: (r) => fmt.money(r.valor), className: 'text-right' },
    { key: 'ativo', label: 'Situação', render: (r) => badge(!!r.ativo) },
  ],
  fields: [
    { name: 'nome', label: 'Nome', required: true, wide: true },
    { name: 'subtitulo', label: 'Subtítulo', wide: true },
    { name: 'listaTipoCursoId', label: 'Tipo de curso', type: 'select', lista: 'tipos-curso', required: true },
    { name: 'listaUnidadeId', label: 'Unidade', type: 'select', lista: 'unidades', required: true },
    { name: 'tipoTurma', label: 'Turma', type: 'select', lista: 'turmas' },
    { name: 'cargaHoraria', label: 'Carga horária (h)', type: 'number' },
    { name: 'media', label: 'Média para aprovação', type: 'decimal' },
    { name: 'faltasPermitidas', label: 'Faltas permitidas', type: 'decimal' },
    { name: 'valor', label: 'Mensalidade (R$)', type: 'decimal' },
    { name: 'valorMatricula', label: 'Matrícula (R$)', type: 'decimal' },
    { name: 'valorRematricula', label: 'Rematrícula (R$)', type: 'decimal' },
    { name: 'periodicidadeCobranca', label: 'Periodicidade da cobrança (meses)', type: 'number' },
    { name: 'tempoCurso', label: 'Duração (meses)', type: 'number' },
    { name: 'horasEstagio', label: 'Horas de estágio', type: 'number' },
    { name: 'limiteAlunosTurma', label: 'Limite de alunos por turma', type: 'number' },
    { name: 'dataMatricula', label: 'Matrículas até', type: 'date' },
    { name: 'dataRematricula', label: 'Rematrículas até', type: 'date' },
    { name: 'foto', label: 'URL da imagem', type: 'url', wide: true },
    { name: 'descricao', label: 'Descrição', type: 'textarea', wide: true },
    { name: 'matriculaAberta', label: 'Matrícula aberta', type: 'checkbox' },
    { name: 'rematriculaAberta', label: 'Rematrícula aberta', type: 'checkbox' },
    { name: 'ativo', label: 'Curso ativo', type: 'checkbox' },
  ],
  defaults: () => ({ ativo: true, periodicidadeCobranca: 1 }),
  formSize: 'xl',
};

const disciplinas: CrudConfig = {
  title: 'Disciplinas',
  subtitle: 'Catálogo de disciplinas reutilizadas nos cursos.',
  endpoint: '/disciplinas',
  singular: 'Disciplina',
  columns: [
    { key: 'valor', label: 'Disciplina' },
    { key: 'cargaHoraria', label: 'Carga horária' },
  ],
  fields: [
    { name: 'valor', label: 'Nome', required: true, wide: true },
    { name: 'cargaHoraria', label: 'Carga horária' },
    { name: 'descricao', label: 'Ementa', type: 'textarea', wide: true },
    { name: 'leituraObrigatoria', label: 'Leitura obrigatória', type: 'textarea', wide: true },
  ],
};

const modulos: CrudConfig = {
  title: 'Módulos do curso',
  subtitle: 'Disciplinas de cada curso, na ordem da trilha sequencial.',
  endpoint: '/modulos',
  singular: 'Módulo',
  filters: [cursoFilter],
  requiredFilter: 'cursoId',
  columns: [
    { key: 'ordem', label: 'Ordem', render: (r) => String(r.ordem ?? '—'), className: 'w-20' },
    { key: 'disciplina', label: 'Disciplina' },
    { key: 'professor', label: 'Professor', render: (r) => String(r.professor ?? '—') },
    { key: 'duracaoDias', label: 'Duração', render: (r) => (r.duracaoDias != null ? `${r.duracaoDias} dias` : '30 dias (padrão)') },
    { key: 'totalVideos', label: 'Vídeos', className: 'text-right' },
  ],
  fields: [
    { name: 'cursoId', label: 'Curso', type: 'select', lista: 'cursos', required: true, wide: true },
    { name: 'listaDisciplinaId', label: 'Disciplina', type: 'select', lista: 'disciplinas', required: true },
    { name: 'listaProfessorId', label: 'Professor', type: 'select', lista: 'professores' },
    { name: 'ordem', label: 'Ordem na trilha', type: 'number', help: 'Define a sequência de liberação.' },
    { name: 'duracaoDias', label: 'Duração mínima (dias)', type: 'number', help: 'Tempo antes de liberar o próximo módulo (padrão 30).' },
    { name: 'periodo', label: 'Período' },
    { name: 'dataInicio', label: 'Data de início', type: 'date' },
  ],
  defaults: (f) => ({ cursoId: f.cursoId }),
};

const videos: CrudConfig = {
  title: 'Aulas online',
  subtitle: 'Vídeo-aulas de cada módulo (YouTube, Vimeo ou link direto).',
  endpoint: '/videos',
  singular: 'Vídeo-aula',
  filters: [cursoFilter, moduloFilter],
  requiredFilter: 'cursoId',
  columns: [
    { key: 'ordem', label: 'Ordem', render: (r) => String(r.ordem ?? '—'), className: 'w-20' },
    { key: 'titulo', label: 'Título' },
    { key: 'disciplina', label: 'Módulo' },
    { key: 'url', label: 'Origem', render: (r) => (r.youtube ? 'YouTube' : r.vimeo ? 'Vimeo' : 'Link') },
  ],
  fields: [
    ...cursoModulo,
    { name: 'titulo', label: 'Título', required: true, wide: true },
    { name: 'url', label: 'URL do vídeo', type: 'url', required: true, wide: true },
    { name: 'ordem', label: 'Ordem no módulo', type: 'number' },
    { name: 'descricao', label: 'Descrição', type: 'textarea', wide: true },
    { name: 'youtube', label: 'Vídeo do YouTube', type: 'checkbox' },
    { name: 'vimeo', label: 'Vídeo do Vimeo', type: 'checkbox' },
  ],
  defaults: (f) => ({ cursoId: f.cursoId ?? '', disciplinaId: f.moduloId ?? '' }),
};

const aulas: CrudConfig = {
  title: 'Aulas presenciais',
  endpoint: '/aulas',
  singular: 'Aula',
  filters: [cursoFilter, moduloFilter],
  requiredFilter: 'cursoId',
  columns: [
    { key: 'dataAula', label: 'Data', render: (r) => fmt.date(r.dataAula) },
    { key: 'inicio', label: 'Horário', render: (r) => `${fmt.time(r.inicio)} – ${fmt.time(r.termino)}` },
    { key: 'titulo', label: 'Título' },
    { key: 'disciplina', label: 'Módulo' },
  ],
  fields: [
    ...cursoModulo,
    { name: 'titulo', label: 'Título', required: true, wide: true },
    { name: 'dataAula', label: 'Data', type: 'date' },
    { name: 'inicio', label: 'Início', type: 'time' },
    { name: 'termino', label: 'Término', type: 'time' },
    { name: 'url', label: 'Link (gravação/áudio)', type: 'url' },
    { name: 'descricao', label: 'Descrição', type: 'textarea', wide: true },
  ],
  defaults: (f) => ({ cursoId: f.cursoId ?? '', disciplinaId: f.moduloId ?? '' }),
};

const estagios: CrudConfig = {
  title: 'Horas de estágio',
  endpoint: '/estagios',
  singular: 'Estágio',
  filters: [cursoFilter],
  columns: [
    { key: 'aluno', label: 'Aluno' },
    { key: 'curso', label: 'Curso' },
    { key: 'horas', label: 'Horas', render: (r) => `${fmt.num(r.horas)} / ${r.horasEstagio ?? '—'}` },
    { key: 'faltas', label: 'Faltas', render: (r) => String(r.faltas ?? '—') },
    { key: 'status', label: 'Situação' },
  ],
  fields: [
    { name: 'cursoId', label: 'Curso', type: 'select', lista: 'cursos', required: true },
    { name: 'usuarioId', label: 'Aluno', type: 'select', lista: 'alunos', required: true },
    { name: 'horas', label: 'Horas cumpridas', type: 'decimal' },
    { name: 'faltas', label: 'Faltas', type: 'number' },
    { name: 'listaStatusNotaId', label: 'Situação', type: 'select', lista: 'status-nota' },
  ],
  defaults: (f) => ({ cursoId: f.cursoId ?? '', listaStatusNotaId: 1 }),
};

export const CursosPage = () => <CrudPage config={cursos} />;
export const DisciplinasPage = () => <CrudPage config={disciplinas} />;
export const ModulosPage = () => <CrudPage config={modulos} />;
export const VideoAulasPage = () => <CrudPage config={videos} />;
export const AulasPage = () => <CrudPage config={aulas} />;
export const EstagiosPage = () => <CrudPage config={estagios} />;

/* ---------- Material (com upload) ---------- */

function UploadButton({ row, reload }: { row: Row; reload: () => void }) {
  const input = useRef<HTMLInputElement>(null);
  const { toast } = useFeedback();
  const [busy, setBusy] = useState(false);
  const onFile = async (file?: File) => {
    if (!file) return;
    setBusy(true);
    try {
      const fd = new FormData();
      fd.append('arquivo', file);
      await upload(`/arquivos/${row.id}/upload`, fd);
      toast('Arquivo enviado.');
      reload();
    } catch (e) {
      toast(e instanceof ApiError ? Object.values(e.fields)[0] ?? e.message : 'Falha no envio.', 'error');
    } finally {
      setBusy(false);
      if (input.current) input.current.value = '';
    }
  };
  return (
    <>
      <input ref={input} type="file" className="hidden" onChange={(e) => onFile(e.target.files?.[0])}
        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.mp3,.zip" />
      <button type="button" disabled={busy} onClick={() => input.current?.click()} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-primary disabled:opacity-50" title="Enviar arquivo">
        <Upload className="h-4 w-4" aria-label="Enviar arquivo" />
      </button>
    </>
  );
}

export function abrirMaterial(url: string | null | undefined, titulo: string) {
  if (!url) return;
  if (url.startsWith('local:') || url.startsWith('/arquivos/')) {
    const id = url.startsWith('/arquivos/') ? url.split('/')[2] : null;
    return id ? download(`/arquivos/${id}/download`, titulo) : undefined;
  }
  window.open(url, '_blank', 'noopener');
}

export function ArquivosPage() {
  const config: CrudConfig = {
    title: 'Material de apoio',
    subtitle: 'Apostilas e arquivos por disciplina. Envie o arquivo após cadastrar (ícone de upload).',
    endpoint: '/arquivos',
    singular: 'Material',
    filters: [{ param: 'disciplinaId', label: 'Disciplina', lista: 'disciplinas' }],
    columns: [
      { key: 'titulo', label: 'Título' },
      { key: 'disciplina', label: 'Disciplina' },
      { key: 'url', label: 'Arquivo', render: (r) => (r.url ? (String(r.url).startsWith('local:') ? 'Enviado' : 'Link externo') : <span className="text-amber-600">Pendente</span>) },
    ],
    fields: [
      { name: 'listaDisciplinaId', label: 'Disciplina', type: 'select', lista: 'disciplinas', required: true, wide: true },
      { name: 'titulo', label: 'Título', required: true, wide: true },
      { name: 'url', label: 'Link externo (opcional)', type: 'url', wide: true, help: 'Ou envie um arquivo depois de salvar.',
        hidden: (f) => String(f.url ?? '').startsWith('local:') },
      { name: 'descricao', label: 'Descrição', type: 'textarea', wide: true },
    ],
    rowActions: (row, reload) => (
      <>
        {row.url ? (
          <button type="button" onClick={() => abrirMaterial(String(row.url).startsWith('local:') ? `/arquivos/${row.id}/download` : String(row.url), String(row.titulo))}
            className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-primary" title="Abrir">
            <Download className="h-4 w-4" aria-label="Abrir" />
          </button>
        ) : null}
        <UploadButton row={row} reload={reload} />
      </>
    ),
  };
  return <CrudPage config={config} />;
}

/* ---------- Provas ---------- */

export function ProvasPage() {
  const [questoesDe, setQuestoesDe] = useState<Row | null>(null);
  const [copiarDe, setCopiarDe] = useState<Row | null>(null);
  const config: CrudConfig = {
    title: 'Provas',
    subtitle: 'Uma prova ativa por módulo. A correção é feita no servidor; o aluno nunca recebe o gabarito.',
    endpoint: '/provas',
    singular: 'Prova',
    filters: [cursoFilter],
    columns: [
      { key: 'curso', label: 'Curso' },
      { key: 'disciplina', label: 'Módulo' },
      { key: 'totalQuestoes', label: 'Questões', className: 'text-right' },
      { key: 'valorTotal', label: 'Valor total', render: (r) => fmt.num(r.valorTotal), className: 'text-right' },
      { key: 'ativa', label: 'Situação', render: (r) => badge(!!r.ativa, 'Ativa', 'Inativa') },
    ],
    fields: [
      ...cursoModulo,
      { name: 'valorProva', label: 'Valor da prova', type: 'decimal' },
      { name: 'ativa', label: 'Prova ativa', type: 'checkbox' },
    ],
    defaults: (f) => ({ ativa: true, cursoId: f.cursoId ?? '' }),
    rowActions: (row) => (
      <>
        <button type="button" onClick={() => setQuestoesDe(row)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-primary" title="Questões">
          <ListChecks className="h-4 w-4" aria-label="Questões" />
        </button>
        <button type="button" onClick={() => setCopiarDe(row)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-primary" title="Copiar para outros módulos">
          <Copy className="h-4 w-4" aria-label="Copiar" />
        </button>
      </>
    ),
  };
  return (
    <>
      <CrudPage config={config} />
      {questoesDe && <QuestoesModal prova={questoesDe} onClose={() => setQuestoesDe(null)} />}
      {copiarDe && <CopiarProvaModal prova={copiarDe} onClose={() => setCopiarDe(null)} />}
    </>
  );
}

/* ---------- Agendamento (atendimento) ---------- */

export function AgendamentoPage() {
  const { user } = useAuth();
  const isStaff = !!user && staff(user);
  const config: CrudConfig = {
    title: 'Atendimento',
    subtitle: isStaff ? 'Agendamentos de atendimento dos alunos.' : 'Agende um atendimento com a secretaria.',
    endpoint: '/agendamentos',
    singular: 'Agendamento',
    filters: [{ param: 'situacaoId', label: 'Situação', lista: 'situacoes-ag' }],
    columns: [
      { key: 'data', label: 'Data', render: (r) => `${fmt.date(r.data)} ${fmt.time(r.hora)}` },
      ...(isStaff ? [{ key: 'usuario', label: 'Aluno' }] : []),
      { key: 'categoria', label: 'Assunto' },
      { key: 'situacao', label: 'Situação' },
      { key: 'descricao', label: 'Descrição', render: (r: Row) => <span className="line-clamp-2">{String(r.descricao ?? '—')}</span> },
    ],
    fields: [
      ...(isStaff ? [{ name: 'usuarioId', label: 'Aluno', type: 'select' as const, lista: 'alunos', required: true, wide: true }] : []),
      { name: 'listaCategoriaAgId', label: 'Assunto', type: 'select', lista: 'categorias-ag', required: true },
      ...(isStaff ? [{ name: 'listaSituacaoAgId', label: 'Situação', type: 'select' as const, lista: 'situacoes-ag' }] : []),
      { name: 'data', label: 'Data', type: 'date', required: true },
      { name: 'hora', label: 'Hora', type: 'time', required: true },
      { name: 'descricao', label: 'Descrição', type: 'textarea', wide: true },
    ],
    canWrite: () => true,
    defaults: () => ({ listaSituacaoAgId: 1 }),
  };
  return <CrudPage config={config} />;
}

