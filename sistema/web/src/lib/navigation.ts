import {
  BookOpen,
  CalendarClock,
  CreditCard,
  DollarSign,
  FileSpreadsheet,
  GraduationCap,
  LayoutDashboard,
  MessageCircle,
  Megaphone,
  MonitorPlay,
  IdCard,
  School,
  Wallet,
  type LucideIcon,
} from 'lucide-react';
import { Perfil, type Me } from './types';

export interface NavItem {
  label: string;
  to?: string;
  icon?: LucideIcon;
  badge?: string;
  children?: NavItem[];
  /** se omitido, visível para todos os autenticados */
  visible?: (u: Me) => boolean;
}

const isAdmin = (u: Me) => u.perfilId === Perfil.Administrador;
const notAluno = (u: Me) => u.perfilId !== Perfil.Aluno;
const liberado = (u: Me) => !(u.inativo || u.pendenciaFinanceira);

/** Mesmas regras de visibilidade do legado Views/Shared/_Navigation.cshtml. */
export const NAVIGATION: NavItem[] = [
  { label: 'Início', to: '/', icon: LayoutDashboard },
  {
    label: 'Painel',
    to: '/painel/online',
    icon: MonitorPlay,
    badge: 'ONLINE',
    visible: (u) => isAdmin(u) || (u.perfilId === Perfil.Aluno && liberado(u)),
  },
  {
    label: 'Painel',
    to: '/painel/presencial',
    icon: School,
    badge: 'PRESENCIAL',
    visible: (u) => isAdmin(u) || (u.perfilId === Perfil.Aluno && u.turmaId === 2 && liberado(u)),
  },
  { label: 'Atendimento', to: '/agendamento', icon: CalendarClock },
  // extrato do aluno: sempre visível (inclusive com pendência, para regularizar)
  { label: 'Minha matrícula', to: '/minha-matricula', icon: IdCard, visible: (u) => u.perfilId === Perfil.Aluno },
  { label: 'Financeiro', to: '/meu-financeiro', icon: Wallet, visible: (u) => u.perfilId === Perfil.Aluno },
  {
    label: 'Acadêmico',
    icon: GraduationCap,
    visible: notAluno,
    children: [
      { label: 'Avisos', to: '/avisos', visible: isAdmin },
      { label: 'Aulas Presenciais', to: '/aulas', visible: isAdmin },
      { label: 'Aulas Online', to: '/video-aulas', visible: isAdmin },
      { label: 'Provas', to: '/provas', visible: isAdmin },
      { label: 'Material', to: '/arquivos', visible: isAdmin },
      { label: 'Cursos', to: '/cursos', visible: isAdmin },
      { label: 'Módulos e trilha', to: '/modulos', visible: isAdmin },
      { label: 'Horas de Estágio', to: '/estagios', visible: isAdmin },
      { label: 'Inscrições', to: '/inscricoes', visible: isAdmin },
      { label: 'Montar Turma', to: '/turmas', visible: isAdmin },
      { label: 'Notas por Aluno', to: '/notas/aluno' },
      { label: 'Notas por Curso', to: '/notas/curso' },
      { label: 'Notas por Disciplina', to: '/notas/disciplina' },
    ],
  },
  {
    label: 'Cadastros',
    icon: BookOpen,
    visible: isAdmin,
    children: [
      { label: 'Contas de recebimento', to: '/contas-bancarias' },
      { label: 'Disciplinas', to: '/disciplinas' },
      { label: 'Instituição', to: '/instituicao' },
      { label: 'Professores', to: '/professores' },
      { label: 'Usuários', to: '/usuarios' },
    ],
  },
  {
    label: 'Cursos',
    icon: GraduationCap,
    visible: isAdmin,
    children: [
      { label: 'Todos', to: '/cursos/todos' },
      { label: 'Meus Cursos', to: '/cursos/meus' },
    ],
  },
  {
    label: 'Financeiro',
    icon: DollarSign,
    visible: isAdmin,
    children: [
      { label: 'Contas Fixas', to: '/financeiro/contas-fixas' },
      { label: 'Contas a Receber', to: '/financeiro/contas-receber' },
      { label: 'Contas a Pagar', to: '/financeiro/contas-pagar' },
    ],
  },
  {
    label: 'Relatórios',
    icon: FileSpreadsheet,
    visible: isAdmin,
    children: [{ label: 'Financeiros', to: '/relatorios/financeiro' }],
  },
  {
    label: 'Cobranças',
    icon: CreditCard,
    visible: isAdmin,
    children: [
      { label: 'Adaline Cobranças', to: '/cobrancas' },
      { label: 'Extrato Boletos', to: '/cobrancas/extrato', visible: (u) => !!u.instituicao.cobrarBoletos },
    ],
  },
  { label: 'Chat', to: '/chat', icon: MessageCircle },
  { label: 'Fórum', to: '/forum', icon: Megaphone, visible: liberado },
];

export function visibleNav(user: Me, items: NavItem[] = NAVIGATION): NavItem[] {
  return items
    .filter((i) => !i.visible || i.visible(user))
    .map((i) => (i.children ? { ...i, children: visibleNav(user, i.children) } : i))
    .filter((i) => !i.children || i.children.length > 0);
}
