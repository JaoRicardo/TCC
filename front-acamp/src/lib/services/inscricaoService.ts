import { apiFetch } from '$lib/api';

export interface MinhaInscricao {
    id: number;
    tipo: string;
    status:
        | 'Inscrito'
        | 'Sorteado'
        | 'Indicado'
        | 'Formulário Enviado'
        | 'Suplente'
        | 'Desistência'
        /** Selecionado, mas não respondeu o formulário no prazo: vaga repassada a um suplente. */
        | 'Prazo Perdido'
        /** A organização corrigiu nascimento, sexo ou cidade e a pessoa não concorre mais. */
        | 'Retirado do sorteio'
        /** A organização pediu correção do formulário (a vaga continua até o prazo dela). */
        | 'Correção pedida'
        /** A organização cancelou a inscrição (motivo em motivoOrganizacao). */
        | 'Cancelada pela organização'
        /** Correção não feita no prazo. */
        | 'Recusada'
        | string;
    dataInscricao?: string;
    isPago?: boolean;
    /** Data-limite para enviar o formulário (YYYY-MM-DDTHH:mm), quando existir. */
    prazoFormulario?: string | null;
    formularioEnviado?: boolean;
    /** Está selecionado e ainda falta enviar o formulário. */
    formularioPendente?: boolean;
    /** A organização já montou o formulário do acampamento desta inscrição. */
    temFormulario?: boolean;
    perdeuPrazo?: boolean;
    /** Motivo da correção pedida, do cancelamento ou da recusa, escrito pela organização. */
    motivoOrganizacao?: string | null;
    /** Quando a organização pediu a correção / cancelou / recusou (YYYY-MM-DDTHH:mm). */
    motivoEm?: string | null;
    /** Prazo da correção pedida (YYYY-MM-DDTHH:mm). */
    prazoCorrecao?: string | null;
    /** "Prazo Perdido": por não enviar o formulário ou por não pagar. */
    perdeuPor?: 'formulario' | 'pagamento' | null;
    /** Data do pagamento aprovado; null quando a organização marcou como pago à mão. */
    pagoEm?: string | null;
    /** Acampamento: continua "Inscrito" depois da data do sorteio (não foi sorteado nem ficou de suplente). */
    naoSorteado?: boolean;
    /** Link de pagamento da atividade: só vem para quem está na hora de pagar. */
    linkPagamento?: string | null;
    activity: { id: number; nome: string; categoria?: string; tipo?: string } | null;
}

export interface Elegibilidade {
    pode: boolean;
    /** nunca_campista, nao_pode_servir, ja_campista, idade, casado_juvenil, discipulados */
    codigo: string | null;
    mensagem: string | null;
}

/** Pode se inscrever como campista/servo (acampamento) ou participante (evento)? */
export async function getElegibilidade(activityId: number): Promise<Record<string, Elegibilidade> | null> {
    const res = await apiFetch(`/activities/${activityId}/elegibilidade`);
    if (!res.ok) return null;
    return (await res.json()).data ?? null;
}

export async function getMinhasInscricoes(): Promise<{ inscricoes: MinhaInscricao[], error?: string }> {
    const res = await apiFetch('/me/inscricoes');
    const data = await res.json();

    if (res.ok) {
        return { inscricoes: data.data || [] };
    }
    return { inscricoes: [], error: data.error || 'Erro ao carregar inscrições' };
}
