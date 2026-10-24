<script lang="ts">
	import { onMount } from 'svelte';
	import { getActivities, deleteActivity as apiDeleteActivity } from '$lib/services/activityService';
	import type { Activity } from '$lib/types/models';
	import { pode } from '$lib/permissoes';

	let activities: Activity[] = $state([]);
	let loading = $state(true);
	let error = $state('');

	async function fetchActivities() {
		try {
			loading = true;
			const res = await getActivities();
			if (!res.error) {
				activities = res.activities;
			} else {
				error = res.error;
			}
		} catch (e) {
			error = 'Falha na conexão com o servidor';
		} finally {
			loading = false;
		}
	}

	onMount(() => {
		fetchActivities();
	});

	async function deleteActivity(id: number, name: string) {
		if (!confirm(`ATENÇÃO: Tem certeza que deseja excluir '${name}'? Esta ação não pode ser desfeita.`)) {
			return;
		}

		try {
			const res = await apiDeleteActivity(id);

			if (res.success) {
				alert(res.message || 'Atividade excluída com sucesso.');
				activities = activities.filter(a => a.id !== id);
			} else {
				alert(res.message || 'Erro ao excluir atividade.');
			}
		} catch (e) {
			alert('Falha na comunicação com o servidor.');
		}
	}

	function formatDate(dateString: string | undefined) {
		if (!dateString) return "A definir";
		return new Date(dateString).toLocaleDateString('pt-BR');
	}
</script>

<svelte:head>
	<title>Gerenciar Atividades | Painel Admin</title>
</svelte:head>

<div class="page-header">
	<div>
		<h2>Gestão de Atividades</h2>
		<p class="text-muted">Lista de todos os Acampamentos e Eventos criados no sistema.</p>
	</div>
</div>

{#if loading}
	<div class="loading-state">
		<div class="spinner"></div>
		<p>Carregando lista de atividades...</p>
	</div>
{:else if error}
	<div class="error-box">
		<p>{error}</p>
		<button onclick={fetchActivities} class="retry-btn">Tentar Novamente</button>
	</div>
{:else}
	<div class="table-container">
		<table class="activities-table">
			<thead>
				<tr>
					<th>ID</th>
					<th>Nome</th>
					<th>Tipo</th>
					<th>Data Inicial</th>
					<th>Local</th>
					<th class="center">Ações</th>
				</tr>
			</thead>
			<tbody>
				{#if activities.length === 0}
					<tr>
						<td colspan="6" class="empty-state">Nenhuma atividade encontrada.</td>
					</tr>
				{/if}
				{#each activities as activity}
					<tr>
						<td>#{activity.id}</td>
						<td>
							<strong>{activity.name}</strong>
						</td>
						<td>
							<span class="type-badge {activity.activitable_type === 'Campings' ? 'camping' : 'event'}">
								{activity.activitable_type === 'Campings' ? 'Acampamento' : 'Evento'}
							</span>
						</td>
						<td>{formatDate(activity.start_date)}</td>
						<td>{activity.place || 'A definir'}</td>
						<td class="actions-cell">
							<a href="/dashboard/atividades/{activity.id}" class="action-btn view-btn" title="Ver Detalhes">👁️</a>
							<!-- Cada ação só aparece para quem tem a tela liberada. -->
							{#if pode('inscricoes')}
								<a href="/admin/inscricoes/{activity.id}" class="action-btn subs-btn" title="Ver Inscritos">📋</a>
							{/if}
							{#if activity.activitable_type === 'Campings' && pode('equipes')}
								<a href="/dashboard/equipes/{activity.id}" class="action-btn team-btn" title="Gerenciar Equipe de Servos">👥</a>
							{/if}

							{#if activity.activitable_type === 'Campings'}
								{#if pode('acampamentos')}
									<a href="/admin/acampamentos/editar/{activity.id}" class="action-btn edit-btn" title="Editar Acampamento">✏️</a>
								{/if}
							{:else if pode('eventos')}
								<!-- Futuro: Editar Evento genérico -->
								<button class="action-btn edit-btn disabled" title="Em breve" disabled>✏️</button>
							{/if}

							{#if pode('excluir_atividades')}
								<button class="action-btn delete-btn" title="Excluir Atividade" onclick={() => deleteActivity(activity.id, activity.name)}>🗑️</button>
							{/if}
						</td>
					</tr>
				{/each}
			</tbody>
		</table>
	</div>
{/if}

<style>
	.page-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 2rem;
		padding-bottom: 1rem;
		border-bottom: 1px solid #e5e7eb;
	}

	h2 {
		margin: 0 0 0.5rem 0;
		font-size: 1.8rem;
		color: #111827;
	}

	.text-muted {
		color: #6b7280;
		margin: 0;
		font-size: 0.95rem;
	}

	.table-container {
		background: white;
		border-radius: 12px;
		box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
		overflow: hidden;
		border: 1px solid #e5e7eb;
	}

	.activities-table {
		width: 100%;
		border-collapse: collapse;
		text-align: left;
	}

	.activities-table th {
		background-color: #f9fafb;
		padding: 1rem 1.5rem;
		font-size: 0.85rem;
		font-weight: 600;
		color: #374151;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		border-bottom: 1px solid #e5e7eb;
	}

	.activities-table td {
		padding: 1rem 1.5rem;
		border-bottom: 1px solid #e5e7eb;
		color: #4b5563;
		vertical-align: middle;
	}

	.activities-table tr:last-child td {
		border-bottom: none;
	}

	.activities-table tr:hover td {
		background-color: #f8fafc;
	}

	.center {
		text-align: center;
	}

	.empty-state {
		text-align: center;
		padding: 3rem !important;
		color: #9ca3af !important;
		font-style: italic;
	}

	.type-badge {
		padding: 0.3rem 0.6rem;
		border-radius: 20px;
		font-size: 0.75rem;
		font-weight: 600;
		text-transform: uppercase;
	}

	.type-badge.camping {
		background-color: #e0f2fe;
		color: #0284c7;
	}

	.type-badge.event {
		background-color: #fce7f3;
		color: #db2777;
	}

	/* Botões de Ação */
	.actions-cell {
		display: flex;
		gap: 0.5rem;
		justify-content: center;
	}

	.action-btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 32px;
		height: 32px;
		border-radius: 6px;
		border: 1px solid transparent;
		background: none;
		cursor: pointer;
		font-size: 1rem;
		text-decoration: none;
		transition: all 0.2s;
	}

	.view-btn {
		background-color: #f3f4f6;
		border-color: #d1d5db;
	}
	.view-btn:hover { background-color: #e5e7eb; }

	.subs-btn {
		background-color: #fef3c7;
		border-color: #fde68a;
	}
	.subs-btn:hover { background-color: #fde68a; }

	.edit-btn {
		background-color: #e0f2fe;
		border-color: #bae6fd;
	}
	.edit-btn:hover:not(:disabled) { background-color: #bae6fd; }

	.delete-btn {
		background-color: #fee2e2;
		border-color: #fecaca;
	}
	.delete-btn:hover { background-color: #fecaca; }

	.disabled {
		opacity: 0.5;
		cursor: not-allowed;
		filter: grayscale(1);
	}

	/* Loaders e Erros */
	.loading-state, .error-box {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		padding: 4rem 2rem;
		background: white;
		border-radius: 12px;
		border: 1px solid #e5e7eb;
	}

	.spinner {
		width: 40px;
		height: 40px;
		border: 4px solid #f3f3f3;
		border-top: 4px solid #10b981;
		border-radius: 50%;
		animation: spin 1s linear infinite;
		margin-bottom: 1rem;
	}

	@keyframes spin {
		0% { transform: rotate(0deg); }
		100% { transform: rotate(360deg); }
	}

	.error-box {
		color: #ef4444;
	}

	.retry-btn {
		margin-top: 1rem;
		padding: 0.5rem 1.5rem;
		background: #ef4444;
		color: white;
		border: none;
		border-radius: 6px;
		cursor: pointer;
		font-weight: 600;
	}
</style>
