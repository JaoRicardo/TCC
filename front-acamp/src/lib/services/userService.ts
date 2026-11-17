import { apiFetch } from '$lib/api';
import type { User } from '$lib/types/models';

// Configuração simples para habilitar Mocks
const USE_MOCKS = import.meta.env.VITE_USE_MOCKS === 'true';

export async function getUsers(): Promise<{ users: User[], catalogo?: ItemPermissao[], error?: string }> {
    if (USE_MOCKS) {
        // Simular latência de rede
        await new Promise(resolve => setTimeout(resolve, 500));
        return {
            users: [
                { id: 1, name: 'Admin Mock', email: 'admin@mock.com', cpf: '111.111.111-11', telas_liberadas: ['usuarios', 'permissoes'], created: new Date().toISOString() },
                { id: 2, name: 'Conselheiro Mock', email: 'cons@mock.com', cpf: '222.222.222-22', telas_liberadas: ['atividades', 'inscricoes'], created: new Date().toISOString() },
                { id: 3, name: 'User Mock', email: 'user@mock.com', cpf: '333.333.333-33', telas_liberadas: [], created: new Date().toISOString() },
            ]
        };
    }

    const res = await apiFetch('/admin/users');
    const data = await res.json();
    if (res.ok) {
        // O catálogo só vem para quem gerencia permissões.
        return { users: data.data || [], catalogo: data.catalogo };
    }
    return { users: [], error: data.error || 'Erro ao carregar usuários' };
}

export interface ItemPermissao {
    chave: string;
    nome: string;
    descricao: string;
    /** Tela que precisa estar ligada junto (ex.: Inscritos e Sorteios abre pela lista de atividades). */
    depende_de: string | null;
    /** Área do painel em que a tela fica (agrupa a janela de permissões e os selos da lista). */
    area: string;
    /** Nome curto da área, usado no selo da lista de pessoas. */
    area_curta: string;
}

export async function getPermissoes(userId: number): Promise<{ catalogo?: ItemPermissao[], ativas?: string[], error?: string }> {
    const res = await apiFetch(`/admin/users/${userId}/permissoes`);
    const data = await res.json();
    if (res.ok) {
        return { catalogo: data.data.catalogo, ativas: data.data.ativas };
    }
    return { error: data.error || 'Erro ao carregar as permissões' };
}

export async function salvarPermissoes(userId: number, permissoes: string[]): Promise<{ ativas?: string[], error?: string }> {
    const res = await apiFetch(`/admin/users/${userId}/permissoes`, {
        method: 'PUT',
        body: JSON.stringify({ permissoes })
    });
    const data = await res.json();
    if (res.ok) {
        return { ativas: data.data.ativas };
    }
    return { error: data.error || 'Erro ao salvar as permissões' };
}

export async function deactivateUser(userId: number): Promise<{ success: boolean, error?: string }> {
    if (USE_MOCKS) {
        await new Promise(resolve => setTimeout(resolve, 300));
        return { success: true };
    }

    const res = await apiFetch(`/admin/users/${userId}`, {
        method: 'DELETE'
    });

    const data = await res.json();
    if (res.ok) {
        return { success: true };
    }
    return { success: false, error: data.error || data.message || 'Erro ao desativar conta' };
}

// ─── Meu cadastro (a própria pessoa) ────────────────────────────────────

export interface EnderecoCadastro {
    street: string;
    number: string;
    neighborhood: string;
    city: string;
    cep: string;
}

export interface MeuCadastro {
    id: number;
    name: string;
    /** Só leitura: é o login. */
    cpf: string;
    email: string | null;
    phone: string;
    birthday: string | null;
    sex: 'M' | 'F' | null;
    marital_status: { id: number; title: string } | null;
    address: EnderecoCadastro | null;
    permissoes: string[];
    created: string | null;
}

export interface DadosDoCadastro {
    name: string;
    email: string;
    phone: string;
    sex: string;
    marital_status_id: number | null;
    address: EnderecoCadastro;
}

export async function getMeuCadastro(): Promise<{ user?: MeuCadastro; error?: string }> {
    const res = await apiFetch('/users/me');
    const data = await res.json().catch(() => ({}));
    if (res.ok) return { user: data.user };
    return { error: data.error || 'Não foi possível carregar o seu cadastro.' };
}

/** Erros por campo vêm com a chave do campo ("email", "address.city"...). */
export async function salvarMeuCadastro(
    dados: DadosDoCadastro
): Promise<{ user?: MeuCadastro; error?: string; errors?: Record<string, string> }> {
    const res = await apiFetch('/users/me', { method: 'PATCH', body: JSON.stringify(dados) });
    const data = await res.json().catch(() => ({}));
    if (res.ok) return { user: data.user };

    const errors: Record<string, string> = {};
    for (const [campo, mensagens] of Object.entries((data.errors ?? {}) as Record<string, Record<string, string>>)) {
        const primeira = Object.values(mensagens ?? {})[0];
        if (primeira) errors[campo] = primeira;
    }
    return { error: data.error || 'Não foi possível salvar o cadastro.', errors };
}

export async function trocarMinhaSenha(senhaAtual: string, novaSenha: string): Promise<{ success: boolean; error?: string }> {
    const res = await apiFetch('/users/me/senha', {
        method: 'POST',
        body: JSON.stringify({ senha_atual: senhaAtual, nova_senha: novaSenha })
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok) return { success: true };
    return { success: false, error: data.error || 'Não foi possível trocar a senha.' };
}

export async function getEstadosCivis(): Promise<{ id: number; title: string }[]> {
    const res = await apiFetch('/marital-statuses');
    const data = await res.json().catch(() => ({}));
    return res.ok ? data.data ?? [] : [];
}

// ─── Ficha da pessoa (painel) ───────────────────────────────────────────

export interface FichaPessoa {
    id: number;
    name: string;
    cpf: string;
    birthday: string | null;
    sex: 'M' | 'F' | null;
    marital_status_id: number | null;
    phone: string;
    email: string | null;
    address: EnderecoCadastro | null;
    created: string | null;
    /** Muda a cada salvamento; vai junto no salvar para não sobrescrever quem salvou depois. */
    versao: string;
    /** Quem usa o painel tem CPF e senha protegidos (só quem cuida das permissões mexe). */
    usa_painel: boolean;
    /** É a única pessoa com "Cargos e permissões": não pode ser desativada. */
    ultimo_gestor: boolean;
    /** Senha provisória gerada e ainda não trocada pela pessoa (data e hora). */
    senha_provisoria_em: string | null;
    /** Só para quem tem "Cargos e permissões". */
    telas_liberadas?: string[];
}

export interface DadosDaFicha {
    versao: string;
    name: string;
    cpf: string;
    birthday: string;
    sex: string;
    marital_status_id: number | null;
    phone: string;
    email: string;
    address: EnderecoCadastro;
}

export interface InscricaoDaPessoa {
    id: number;
    atividade: string;
    ano: number | string | null;
    /** Campista, Servo ou Participante de evento. */
    como: string;
    status: string;
    /** A atividade ainda não terminou. */
    andamento: boolean;
}

/** `naoEncontrada`: a pessoa foi desativada ou o link é antigo. */
export async function getFicha(
    userId: number
): Promise<{ ficha?: FichaPessoa; catalogo?: ItemPermissao[]; naoEncontrada?: boolean; error?: string }> {
    const res = await apiFetch(`/admin/users/${userId}`);
    const data = await res.json().catch(() => ({}));
    if (res.ok) return { ficha: data.data, catalogo: data.catalogo };
    if (res.status === 404) return { naoEncontrada: true };
    return { error: data.error || 'Não foi possível abrir a ficha.' };
}

/**
 * Erros por campo vêm com a chave do campo ("cpf", "address.city"...).
 * `outraConta`: dona do CPF digitado. `conflito`: alguém salvou a ficha depois que ela foi aberta.
 */
/** Resposta de erro do salvar e da prévia (campos, CPF de outra conta, versão velha). */
export interface ErrosDaFicha {
    error?: string;
    errors?: Record<string, string>;
    outraConta?: { id: number; name: string } | null;
    conflito?: boolean;
}

function errosDaFicha(res: Response, data: any): ErrosDaFicha {
    const errors: Record<string, string> = {};
    for (const [campo, mensagens] of Object.entries((data.errors ?? {}) as Record<string, Record<string, string>>)) {
        const primeira = Object.values(mensagens ?? {})[0];
        if (primeira) errors[campo] = primeira;
    }
    return {
        error: data.error || 'Não foi possível salvar. Verifique sua conexão e tente de novo.',
        errors,
        outraConta: data.outra_conta ?? null,
        conflito: res.status === 409
    };
}

export async function salvarFicha(
    userId: number,
    dados: DadosDaFicha
): Promise<{ ficha?: FichaPessoa; retiradas?: number } & ErrosDaFicha> {
    const res = await apiFetch(`/admin/users/${userId}`, { method: 'PATCH', body: JSON.stringify(dados) });
    const data = await res.json().catch(() => ({}));
    if (res.ok) return { ficha: data.data, retiradas: data.retiradas ?? 0 };
    return errosDaFicha(res, data);
}

/** Inscrição que sai do sorteio com os dados novos, e por quê. */
export interface InscricaoAfetada {
    id: number;
    atividade: string;
    ano: number | string | null;
    como: string;
    motivo: 'idade' | 'sexo' | 'cidade';
}

export interface PreviaDaFicha {
    afetadas: InscricaoAfetada[];
    /** Há inscrições já sorteadas, de suplente, indicadas ou com formulário enviado (não mudam). */
    ja_sorteadas: boolean;
    saiu_da_area: boolean;
}

/** O mesmo corpo do salvar, sem gravar: para a janela "Conferir antes de salvar". */
export async function previaFicha(userId: number, dados: DadosDaFicha): Promise<{ previa?: PreviaDaFicha } & ErrosDaFicha> {
    const res = await apiFetch(`/admin/users/${userId}/previa`, { method: 'POST', body: JSON.stringify(dados) });
    const data = await res.json().catch(() => ({}));
    if (res.ok) return { previa: data.data };
    return errosDaFicha(res, data);
}

export async function getInscricoesDaPessoa(userId: number): Promise<{ inscricoes?: InscricaoDaPessoa[]; error?: string }> {
    const res = await apiFetch(`/admin/users/${userId}/inscricoes`);
    const data = await res.json().catch(() => ({}));
    if (res.ok) return { inscricoes: data.data ?? [] };
    return { error: data.error || 'Não foi possível carregar as inscrições.' };
}

/** A senha vem só nesta resposta: depois disso, ninguém a vê de novo. */
export async function gerarSenhaProvisoria(
    userId: number
): Promise<{ senha?: string; geradaEm?: string; error?: string }> {
    const res = await apiFetch(`/admin/users/${userId}/senha-provisoria`, { method: 'POST' });
    const data = await res.json().catch(() => ({}));
    if (res.ok) return { senha: data.data.senha, geradaEm: data.data.senha_provisoria_em };
    return { error: data.error || 'Não foi possível gerar a senha. Verifique sua conexão e tente de novo.' };
}
