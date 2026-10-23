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
