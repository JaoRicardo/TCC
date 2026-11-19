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
        /** A organização pediu correção do formulário (a vaga continua); "atrasada" depois do prazo dela. */
        | 'Correção pedida'
        | 'Correção atrasada'
        /** A organização cancelou a inscrição (motivo em motivoOrganizacao). */
        | 'Cancelada pela organização'
        /** Correção não feita até o início da atividade. */
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
    activity: { id: number; nome: string; categoria?: string } | null;
}

export async function getMinhasInscricoes(): Promise<{ inscricoes: MinhaInscricao[], error?: string }> {
    const res = await apiFetch('/me/inscricoes');
    const data = await res.json();

    if (res.ok) {
        return { inscricoes: data.data || [] };
    }
    return { inscricoes: [], error: data.error || 'Erro ao carregar inscrições' };
}
