<script lang="ts">
	import { onMount } from 'svelte';
	import { getUsers, toggleUserRole, deactivateUser } from '$lib/services/userService';
	import type { User } from '$lib/types/models';
	import { auth } from '$lib/stores/auth.svelte';

	let users: User[] = $state([]);
	let loading = $state(true);
	let error = $state('');

	async function fetchUsers() {
		try {
			loading = true;
			const res = await getUsers();
			if (!res.error) {
				users = res.users;
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
		fetchUsers();
	});

	async function toggleRole(userId: number, roleType: 'is_admin' | 'is_counselor', currentValue: boolean) {
		try {
			const res = await toggleUserRole(userId, roleType, currentValue);
			
			if (res.success) {
				users = users.map(u => {
					if (u.id === userId) {
						return { ...u, [roleType]: !currentValue };
					}
					return u;
				});
			} else {
				alert(res.error || 'Erro ao atualizar privilégio');
				// Force table re-render to reset toggle state
				users = [...users];
			}
		} catch (e) {
			alert('Falha na comunicação com o servidor');
			users = [...users];
		}
	}

	async function handleDeactivate(userId: number, userName: string) {
		if (confirm(`Tem certeza que deseja desativar a conta de ${userName}? Essa ação não pode ser desfeita.`)) {
			try {
				const res = await deactivateUser(userId);
				if (res.success) {
					users = users.filter(u => u.id !== userId);
				} else {
					alert(res.error || 'Erro ao desativar conta');
				}
			} catch (e) {
				alert('Falha na comunicação com o servidor');
			}
		}
	}
</script>

<div class="page-header">
	<div>
		<h2>Gestão de Usuários</h2>
		<p class="text-muted">Gerencie quem tem permissão para administrar ou aconselhar no sistema.</p>
	</div>
</div>

{#if loading}
	<div class="loading-state">
		<div class="spinner"></div>
		<p>Carregando lista de usuários...</p>
	</div>
{:else if error}
	<div class="error-box">
		<p>{error}</p>
		<button onclick={fetchUsers} class="retry-btn">Tentar Novamente</button>
	</div>
{:else}
	<div class="table-container">
		<table class="users-table">
			<thead>
				<tr>
					<th>Nome</th>
					<th>CPF</th>
					<th>E-mail</th>
					<th>Telefone</th>
					<th>Nascimento</th>
					<th>Cadastro</th>
					<th class="center">Conselheiro</th>
					<th class="center">Admin</th>
					<th class="center">Ações</th>
				</tr>
			</thead>
			<tbody>
				{#each users as user}
					<tr>
						<td>
							<div class="user-name">
								{user.name}
								{#if user.id === auth.user?.id}
									<span class="badge-you">Você</span>
								{/if}
							</div>
						</td>
						<td><span class="mono">{user.cpf}</span></td>
						<td><span class="mono">{user.email}</span></td>
						<td><span class="mono">{user.phone || '-'}</span></td>
						<td><span class="date">{user.birthday ? new Date(user.birthday + 'T12:00:00Z').toLocaleDateString('pt-BR') : '-'}</span></td>
						<td><span class="date">{user.created ? new Date(user.created).toLocaleDateString('pt-BR') : '-'}</span></td>
						<td class="center">
							<label class="toggle-switch" class:disabled={user.id === auth.user?.id}>
								<input 
									type="checkbox" 
									checked={user.is_counselor} 
									disabled={user.id === auth.user?.id}
									onchange={() => toggleRole(user.id, 'is_counselor', user.is_counselor)}
								/>
								<span class="slider"></span>
							</label>
						</td>
						<td class="center">
							<label class="toggle-switch admin-toggle" class:disabled={user.id === auth.user?.id}>
								<input 
									type="checkbox" 
									checked={user.is_admin} 
									disabled={user.id === auth.user?.id}
									onchange={() => toggleRole(user.id, 'is_admin', user.is_admin)}
								/>
								<span class="slider"></span>
							</label>
						</td>
						<td class="center">
							<button 
								class="delete-btn" 
								disabled={user.id === auth.user?.id}
								onclick={() => handleDeactivate(user.id, user.name)}
								title="Desativar conta"
							>
								Excluir
							</button>
						</td>
					</tr>
				{/each}
				
				{#if users.length === 0}
					<tr>
						<td colspan="9" class="empty-state">Nenhum usuário encontrado.</td>
					</tr>
				{/if}
			</tbody>
		</table>
	</div>
{/if}

<style>
	.page-header {
		margin-bottom: 2rem;
	}

	.page-header h2 {
		margin: 0 0 0.5rem 0;
		font-size: 1.5rem;
		color: #111827;
	}

	.text-muted {
		color: #6b7280;
		margin: 0;
		font-size: 0.95rem;
	}

	/* Loading and Error states */
	.loading-state, .error-box {
		background: #fff;
		border-radius: 12px;
		padding: 4rem 2rem;
		text-align: center;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05);
		border: 1px solid #e5e7eb;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 1rem;
	}

	.error-box {
		color: #dc2626;
	}

	.retry-btn {
		background: #fee2e2;
		color: #dc2626;
		border: 1px solid #f87171;
		padding: 0.5rem 1rem;
		border-radius: 6px;
		font-weight: 600;
		cursor: pointer;
	}

	.spinner {
		width: 40px;
		height: 40px;
		border: 4px solid rgba(28, 139, 107, 0.2);
		border-top-color: #1c8b6b;
		border-radius: 50%;
		animation: spin 1s linear infinite;
	}

	@keyframes spin {
		to { transform: rotate(360deg); }
	}

	/* Table Design */
	.table-container {
		background: #fff;
		border-radius: 12px;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05);
		border: 1px solid #e5e7eb;
		overflow-x: auto;
	}

	.users-table {
		width: 100%;
		border-collapse: collapse;
		text-align: left;
	}

	.users-table th {
		background: #f9fafb;
		padding: 1rem 1.5rem;
		font-size: 0.75rem;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: #6b7280;
		font-weight: 600;
		border-bottom: 1px solid #e5e7eb;
	}

	.users-table td {
		padding: 1rem 1.5rem;
		border-bottom: 1px solid #f3f4f6;
		color: #374151;
		font-size: 0.95rem;
		vertical-align: middle;
	}

	.users-table tr:last-child td {
		border-bottom: none;
	}

	.users-table tr:hover td {
		background-color: #f8fafc;
	}

	.center {
		text-align: center;
	}

	.user-name {
		font-weight: 600;
		color: #111827;
		display: flex;
		align-items: center;
		gap: 0.5rem;
	}

	.badge-you {
		background: #dbeafe;
		color: #1e40af;
		font-size: 0.7rem;
		padding: 0.1rem 0.4rem;
		border-radius: 4px;
		text-transform: uppercase;
		font-weight: 700;
	}

	.mono {
		font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
		font-size: 0.85rem;
		color: #6b7280;
	}

	.date {
		color: #6b7280;
		font-size: 0.9rem;
	}

	.empty-state {
		text-align: center;
		padding: 3rem !important;
		color: #6b7280;
		font-style: italic;
	}

	/* Toggle Switches */
	.toggle-switch {
		position: relative;
		display: inline-block;
		width: 44px;
		height: 24px;
	}

	.toggle-switch input {
		opacity: 0;
		width: 0;
		height: 0;
	}

	.slider {
		position: absolute;
		cursor: pointer;
		top: 0; left: 0; right: 0; bottom: 0;
		background-color: #e5e7eb;
		transition: .3s;
		border-radius: 34px;
	}

	.slider:before {
		position: absolute;
		content: "";
		height: 18px;
		width: 18px;
		left: 3px;
		bottom: 3px;
		background-color: white;
		transition: .3s;
		border-radius: 50%;
		box-shadow: 0 1px 2px rgba(0,0,0,0.1);
	}

	input:checked + .slider {
		background-color: #10b981; /* Verde Conselheiro */
	}

	input:focus + .slider {
		box-shadow: 0 0 1px #10b981;
	}

	input:checked + .slider:before {
		transform: translateX(20px);
	}

	/* Toggle para Admin com cor diferente */
	.admin-toggle input:checked + .slider {
		background-color: #3b82f6; /* Azul Admin */
	}

	.admin-toggle.disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}
	
	.admin-toggle.disabled .slider {
		cursor: not-allowed;
	}

	.delete-btn {
		background-color: #fee2e2;
		color: #dc2626;
		border: 1px solid #f87171;
		padding: 0.4rem 0.8rem;
		border-radius: 6px;
		font-size: 0.8rem;
		font-weight: 600;
		cursor: pointer;
		transition: all 0.2s;
	}
	.delete-btn:hover:not(:disabled) {
		background-color: #fca5a5;
		color: #991b1b;
	}
	.delete-btn:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}
</style>
