export enum Perfil {
  Administrador = 1,
  Professor = 2,
  Aluno = 3,
}

export interface InstituicaoTema {
  id: number | null;
  nome: string;
  titulo: string;
  logo: string | null;
  corPrimaria: string;
  celular: string | null;
  vencimento?: string | null;
  cobrarBoletos?: boolean;
}

export interface Me {
  id: number;
  nome: string;
  email: string;
  matricula: string | null;
  foto: string | null;
  perfilId: Perfil;
  perfil: string | null;
  turmaId: number | null;
  unidadeId: number;
  master: boolean;
  inativo: boolean;
  mensagensNaoLidas: number;
  pendenciaFinanceira: boolean;
  instituicao: InstituicaoTema;
}
