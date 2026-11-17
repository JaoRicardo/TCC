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
