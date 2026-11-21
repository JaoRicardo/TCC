import type { DatasDaAtividade } from '$lib/faseAtividade';

export interface User {
    id: number;
    name: string;
    email: string;
    cpf: string;
    /** Telas do painel liberadas (só vem no cadastro da própria pessoa). */
    permissoes?: string[];
    /** Telas do painel que a pessoa acessa (só vem na lista para quem gerencia permissões). */
    telas_liberadas?: string[];
    birthday?: string;
    phone?: string;
    created?: string;
    modified?: string;
}

export interface Activity {
    id: number;
    name: string;
    activitable_type: 'Campings' | 'Events' | string;
    start_date?: string;
    end_date?: string;
    place?: string;
    created?: string;
    modified?: string;
    image?: string;
    category?: { id?: number; name: string };
    /** Evento com formulário de inscrição (null = conclui a inscrição na hora). */
    formularioId?: number | null;
    targetAudience?: string;
    status?: 'Em Breve' | 'Inscrições Abertas' | 'Encerrado' | string;
    /** Faixa etária do evento (recusa quem está fora dela). */
    idadeMinima?: number | null;
    idadeMaxima?: number | null;
    /** Datas que decidem a fase (selo do Início): `lib/faseAtividade.ts`. */
    datas: DatasDaAtividade;
}
