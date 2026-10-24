import { apiFetch } from '$lib/api';
import type { User } from '$lib/types/models';

// Configuração simples para habilitar Mocks
const USE_MOCKS = import.meta.env.VITE_USE_MOCKS === 'true';

export async function getUsers(): Promise<{ users: User[], error?: string }> {
    if (USE_MOCKS) {
        // Simular latência de rede
        await new Promise(resolve => setTimeout(resolve, 500));
        return {
            users: [
                { id: 1, name: 'Admin Mock', email: 'admin@mock.com', cpf: '111.111.111-11', telas_liberadas: 10, created: new Date().toISOString() },
                { id: 2, name: 'Conselheiro Mock', email: 'cons@mock.com', cpf: '222.222.222-22', telas_liberadas: 2, created: new Date().toISOString() },
                { id: 3, name: 'User Mock', email: 'user@mock.com', cpf: '333.333.333-33', telas_liberadas: 0, created: new Date().toISOString() },
            ]
        };
    }

    const res = await apiFetch('/admin/users');
    const data = await res.json();
    if (res.ok) {
        return { users: data.data || [] };
    }
    return { users: [], error: data.error || 'Erro ao carregar usuários' };
}

export interface ItemPermissao {
    chave: string;
    nome: string;
    descricao: string;
    /** Tela que precisa estar ligada junto (ex.: Inscritos e Sorteios abre pela lista de atividades). */
    depende_de: string | null;
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
