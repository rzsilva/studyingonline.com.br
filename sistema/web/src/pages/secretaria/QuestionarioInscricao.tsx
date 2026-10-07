import { Card, Input } from '../../components/ui';
import { Checkbox, Textarea } from '../../components/form';

/** Perfis vindos de LISTA_TIPO_CURSO.FORMULARIO (views InscricaoEAD/Kids/Teen/... do legado). */
export type PerfilFormulario = 'basico' | 'ead' | 'completo' | 'kids' | 'teen';

type Tipo = 'text' | 'email' | 'date' | 'tel' | 'bool' | 'area';
interface Campo { k: string; label: string; tipo?: Tipo; wide?: boolean; se?: string }
interface Secao { titulo: string; descricao?: string; perfis: PerfilFormulario[]; campos: Campo[] }

const INFANTIL: PerfilFormulario[] = ['kids', 'teen'];
const COM_SAUDE: PerfilFormulario[] = ['completo', 'kids', 'teen'];

const SECOES: Secao[] = [
  {
    titulo: 'Dados complementares',
    perfis: ['ead', 'completo', 'kids', 'teen'],
    campos: [
      { k: 'filiacao', label: 'Filiação (nome do pai e da mãe)', wide: true },
      { k: 'rgOrgaoEmissor', label: 'Órgão emissor do RG' },
      { k: 'rgDataEmissao', label: 'Data de emissão do RG', tipo: 'date' },
      { k: 'passaporteNumero', label: 'Passaporte (se estrangeiro)' },
      { k: 'passaporteValidade', label: 'Validade do passaporte', tipo: 'date' },
      { k: 'telefone2', label: 'Telefone alternativo', tipo: 'tel' },
    ],
  },
  {
    titulo: 'Vida na igreja',
    descricao: 'Sobre a igreja que você frequenta atualmente.',
    perfis: ['ead', 'completo', 'kids', 'teen'],
    campos: [
      { k: 'igrejaNome', label: 'Igreja que frequenta', wide: true },
      { k: 'igrejaTelefone', label: 'Telefone da igreja', tipo: 'tel' },
      { k: 'igrejaTempo', label: 'Há quanto tempo frequenta' },
      { k: 'igrejaMembro', label: 'Sou membro desta igreja', tipo: 'bool' },
      { k: 'igrejaNumeroMembro', label: 'Número de membro (se houver)', se: 'igrejaMembro' },
      { k: 'igrejaFreqRegular', label: 'Frequento regularmente', tipo: 'bool' },
      { k: 'igrejaPastorNome', label: 'Nome do pastor' },
      { k: 'igrejaPastorEmail', label: 'E-mail do pastor', tipo: 'email' },
      { k: 'igrejaLiderNome', label: 'Nome do líder' },
      { k: 'igrejaLiderEmail', label: 'E-mail do líder', tipo: 'email' },
      { k: 'igrejaAtividadesEnvolvidas', label: 'Em quais atividades da igreja está ou esteve envolvido?', tipo: 'area', wide: true },
      { k: 'igrejaHabilidades', label: 'Habilidades e dons', tipo: 'area', wide: true },
    ],
  },
  {
    titulo: 'Endereço da igreja',
    perfis: COM_SAUDE,
    campos: [
      { k: 'igrejaCep', label: 'CEP' }, { k: 'igrejaRua', label: 'Rua' }, { k: 'igrejaNumero', label: 'Número' },
      { k: 'igrejaBairro', label: 'Bairro' }, { k: 'igrejaCidade', label: 'Cidade' }, { k: 'igrejaUf', label: 'UF' },
      { k: 'igrejaRazaoMudanca', label: 'Se frequenta a igreja atual há menos de 1 ano, informe o motivo e a igreja anterior', tipo: 'area', wide: true },
      { k: 'igrejaDesviou', label: 'Nos últimos 2 anos esteve afastado da igreja por algum período', tipo: 'bool', wide: true },
      { k: 'igrejaDesviouExplique', label: 'Explique', tipo: 'area', wide: true, se: 'igrejaDesviou' },
    ],
  },
  {
    titulo: 'Igreja da criança',
    descricao: 'Igreja que o aluno frequenta (preenchido pelo responsável).',
    perfis: INFANTIL,
    campos: [
      { k: 'igrejaKid', label: 'Igreja', wide: true },
      { k: 'igrejaCepKid', label: 'CEP' }, { k: 'igrejaRuaKid', label: 'Rua' }, { k: 'igrejaNumeroKid', label: 'Número' },
      { k: 'igrejaBairroKid', label: 'Bairro' }, { k: 'igrejaCidadeKid', label: 'Cidade' }, { k: 'igrejaUfKid', label: 'UF' },
    ],
  },
  {
    titulo: 'Fé e chamado',
    perfis: ['ead', 'completo', 'kids', 'teen'],
    campos: [
      { k: 'igrejaFePalavraInspirada', label: 'Creio que a Bíblia é a Palavra inspirada de Deus', tipo: 'bool', wide: true },
      { k: 'igrejaFeTrindade', label: 'Creio na Trindade', tipo: 'bool', wide: true },
      { k: 'igrejaFeJesus', label: 'Creio em Jesus Cristo como Senhor e Salvador', tipo: 'bool', wide: true },
      { k: 'dataConversao', label: 'Data da conversão', tipo: 'date' },
      { k: 'dataBatismo', label: 'Data do batismo', tipo: 'date' },
      { k: 'igrejaBatismo', label: 'Igreja onde foi batizado', wide: true },
      { k: 'justificativaSalvacao', label: 'Conte como foi sua conversão', tipo: 'area', wide: true },
      { k: 'escolhaSeminario', label: 'Por que escolheu este curso?', tipo: 'area', wide: true },
    ],
  },
  {
    titulo: 'Saúde',
    descricao: 'Informações confidenciais, usadas só pela secretaria em caso de necessidade.',
    perfis: COM_SAUDE,
    campos: [
      { k: 'doencasLimitacoes', label: 'Doenças ou limitações físicas', tipo: 'area', wide: true },
      { k: 'transtornoDoenca', label: 'Possui algum transtorno ou doença física ou mental', tipo: 'bool', wide: true },
      { k: 'justificativaTranstorno', label: 'Qual?', tipo: 'area', wide: true, se: 'transtornoDoenca' },
      { k: 'remedioControlado', label: 'Toma remédio controlado? Qual e para quê?', wide: true },
      { k: 'alergiaMedicamento', label: 'Alergia a medicamentos', wide: true },
      { k: 'saudeGeral', label: 'Como está sua saúde em geral?', wide: true },
      { k: 'saudeObservacao', label: 'Observações', tipo: 'area', wide: true },
    ],
  },
];

/** Campos de contato de emergência além de nome/celular (que o formulário básico já tem). */
export const EMERGENCIA_EXTRA: Campo[] = [
  { k: 'emergenciaParentesco', label: 'Parentesco' },
  { k: 'emergenciaTelefone', label: 'Telefone', tipo: 'tel' },
];

export function QuestionarioInscricao({ perfil, valores, onChange }: {
  perfil: PerfilFormulario;
  valores: Record<string, string | boolean>;
  onChange: (k: string, v: string | boolean) => void;
}) {
  const infantil = INFANTIL.includes(perfil);
  return (
    <>
      {SECOES.filter((s) => s.perfis.includes(perfil)).map((s) => (
        <Card key={s.titulo} title={s.titulo === 'Vida na igreja' && infantil ? 'Vida na igreja (responsável)' : s.titulo}>
          {s.descricao && <p className="-mt-2 mb-4 text-sm text-slate-500">{s.descricao}</p>}
          <div className="grid gap-4 sm:grid-cols-2">
            {s.campos.filter((c) => !c.se || valores[c.se] === true).map((c) => <CampoQ key={c.k} c={c} v={valores[c.k]} onChange={onChange} />)}
          </div>
        </Card>
      ))}
    </>
  );
}

export function CampoQ({ c, v, onChange }: { c: Campo; v: string | boolean | undefined; onChange: (k: string, v: string | boolean) => void }) {
  const wide = c.wide ? 'sm:col-span-2' : undefined;
  if (c.tipo === 'bool') {
    return <Checkbox className={wide} name={c.k} label={c.label} checked={v === true} onChange={(e) => onChange(c.k, e.target.checked)} />;
  }
  if (c.tipo === 'area') {
    return <Textarea className={wide} name={c.k} label={c.label} maxLength={2000} value={(v as string) ?? ''} onChange={(e) => onChange(c.k, e.target.value)} />;
  }
  return (
    <Input className={wide} name={c.k} label={c.label} type={c.tipo ?? 'text'} maxLength={200}
      value={(v as string) ?? ''} onChange={(e) => onChange(c.k, e.target.value)} />
  );
}
