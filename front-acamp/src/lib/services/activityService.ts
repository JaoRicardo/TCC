import { apiFetch } from '$lib/api';
import type { Activity } from '$lib/types/models';

const USE_MOCKS = false; // Mocks desativados para usar a API real

export async function getActivities(): Promise<{ activities: Activity[], error?: string }> {
    if (USE_MOCKS) {
        await new Promise(resolve => setTimeout(resolve, 500));
        return {
            activities: [
                { id: 1, name: 'Acampamento de Verão 2026', activitable_type: 'Campings', start_date: '2026-12-10T10:00:00Z', place: 'Sítio Recanto Feliz', category: { name: 'Jovens' }, image: 'https://images.unsplash.com/photo-1537225228614-56cc3556d7ed?w=800&q=80' },
                { id: 2, name: 'Retiro Espiritual de Inverno', activitable_type: 'Campings', start_date: '2026-07-20T08:00:00Z', place: 'Fazenda Boa Vista', category: { name: 'Adultos' }, image: 'https://images.unsplash.com/photo-1515444744559-7be63e1600de?w=800&q=80' },
                { id: 3, name: 'Luau com Cristo', activitable_type: 'Events', start_date: '2026-09-15T19:00:00Z', place: 'Praia do Sol', category: { name: 'Jovens' }, image: 'https://images.unsplash.com/photo-1527529482837-4698179dc6ce?w=800&q=80' },
                { id: 4, name: 'Seminário de Casais', activitable_type: 'Events', start_date: '2026-10-05T09:00:00Z', place: 'Auditório Principal', category: { name: 'Casais' }, image: 'https://images.unsplash.com/photo-1511895426328-dc8714191300?w=800&q=80' },
                { id: 5, name: 'Festa das Crianças', activitable_type: 'Events', start_date: '2026-10-12T14:00:00Z', place: 'Salão Paroquial', category: { name: 'Crianças' }, image: 'https://images.unsplash.com/photo-1530103043063-8a3560731f82?w=800&q=80' }
            ]
        };
    }

    const res = await apiFetch('/activities');
    const data = await res.json();
    
    if (res.ok) {
        const backendData = data.data || [];
        const mappedActivities = backendData.map((b: any) => ({
            id: b.id,
            name: b.nome,
            activitable_type: b.tipo === 'Acampamento' ? 'Campings' : 'Events',
            start_date: b.inicioEvento,
            end_date: b.fimEvento,
            place: b.local,
            image: b.imagem,
            category: b.categoria ? { name: b.categoria.nome } : null,
            targetAudience: b.targetAudience || b.publicoAlvo,
            status: b.status
        }));
        return { activities: mappedActivities };
    }
    return { activities: [], error: data.error || 'Erro ao carregar atividades' };
}

export async function deleteActivity(id: number): Promise<{ success: boolean, message?: string }> {
    if (USE_MOCKS) {
        await new Promise(resolve => setTimeout(resolve, 300));
        return { success: true, message: 'Atividade excluída com sucesso no mock' };
    }

    const res = await apiFetch(`/activities/${id}`, {
        method: 'DELETE'
    });
    const data = await res.json();

    if (res.ok) {
        return { success: true, message: 'Atividade excluída com sucesso.' };
    }
    return { success: false, message: data.message || 'Erro ao excluir atividade.' };
}
