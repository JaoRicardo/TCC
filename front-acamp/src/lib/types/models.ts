export interface User {
    id: number;
    name: string;
    email: string;
    cpf: string;
    is_admin: boolean;
    is_counselor: boolean;
    /** Telas do painel liberadas (só vem no cadastro da própria pessoa). */
    permissoes?: string[];
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
    category?: { name: string };
    targetAudience?: string;
    status?: 'Em Breve' | 'Inscrições Abertas' | 'Encerrado' | string;
}
