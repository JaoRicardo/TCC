<script lang="ts">
	import { onMount } from 'svelte';
	import {
		getUsers,
		deactivateUser,
		getPermissoes,
		salvarPermissoes,
		type ItemPermissao
	} from '$lib/services/userService';
	import type { User } from '$lib/types/models';
	import { auth } from '$lib/stores/auth.svelte';
	import { pode } from '$lib/permissoes';

	// A coluna "Painel" e a janela de permissões só aparecem para quem gerencia permissões.
	let gerenciaPermissoes = $derived(pode('permissoes'));

	// Janela "Permissões" de uma pessoa.
	let editando: User | null = $state(null);
	let catalogo: ItemPermissao[] = $state([]);
	let marcadas: string[] = $state([]);
	let carregandoPermissoes = $state(false);
	let salvandoPermissoes = $state(false);
	let erroPermissoes = $state('');

	async function abrirPermissoes(user: User) {
		editando = user;
		erroPermissoes = '';
		carregandoPermissoes = true;
		const res = await getPermissoes(user.id);
		carregandoPermissoes = false;
		if (res.error) {
			erroPermissoes = res.error;
			return;
		}
		catalogo = res.catalogo ?? [];
		marcadas = res.ativas ?? [];
	}

	function fecharPermissoes() {
		editando = null;
	}

	// A própria pessoa não pode tirar de si a permissão de gerenciar permissões.
	function travada(chave: string) {
		return editando?.id === auth.user?.id && (chave === 'permissoes' || chave === 'usuarios');
	}

	// Ligar uma tela liga também a tela por onde ela se abre; desligar essa tela
	// desliga as que dependem dela.
	function alternar(chave: string) {
		if (travada(chave)) return;
		if (marcadas.includes(chave)) {
			const dependentes = catalogo.filter((i) => i.depende_de === chave).map((i) => i.chave);
			marcadas = marcadas.filter((c) => c !== chave && !dependentes.includes(c));
		} else {
			const base = catalogo.find((i) => i.chave === chave)?.depende_de;
			marcadas = [...marcadas, chave, ...(base && !marcadas.includes(base) ? [base] : [])];
		}
	}

	function ativarTudo() {
		marcadas = catalogo.map((i) => i.chave);
	}

	function desativarTudo() {
		marcadas = catalogo.map((i) => i.chave).filter((c) => travada(c));
	}

	async function confirmarPermissoes() {
		if (!editando) return;
		salvandoPermissoes = true;
		erroPermissoes = '';
		const res = await salvarPermissoes(editando.id, marcadas);
		salvandoPermissoes = false;
		if (res.error) {
			erroPermissoes = res.error;
			return;
		}
		const id = editando.id;
		users = users.map((u) => (u.id === id ? { ...u, telas_liberadas: res.ativas?.length ?? 0 } : u));
		// Mudou as próprias permissões: atualiza o menu na hora.
		if (id === auth.user?.id) {
			await auth.verifyToken();
		}
		editando = null;
	}

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
		<p class="text-muted">Contas cadastradas no sistema.</p>
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
					{#if gerenciaPermissoes}
						<th class="center">Painel</th>
					{/if}
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
						{#if gerenciaPermissoes}
							<!-- Quantas telas do painel a pessoa acessa; sem nenhuma, não entra no painel. -->
							<td class="center">
								{#if user.telas_liberadas}
									<span class="badge-painel">{user.telas_liberadas} {user.telas_liberadas === 1 ? 'tela' : 'telas'}</span>
								{:else}
									<span class="date">—</span>
								{/if}
							</td>
						{/if}
						<td class="center actions-cell">
							{#if gerenciaPermissoes}
								<button class="perm-btn" onclick={() => abrirPermissoes(user)} title="Escolher as telas que a pessoa acessa">
									Permissões
								</button>
							{/if}
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
						<td colspan={gerenciaPermissoes ? 8 : 7} class="empty-state">Nenhum usuário encontrado.</td>
					</tr>
				{/if}
			</tbody>
		</table>
	</div>
{/if}

{#if editando}
	<div class="modal-fundo" role="presentation" onclick={fecharPermissoes}>
		<div
			class="modal"
			role="dialog"
			aria-modal="true"
			aria-labelledby="titulo-permissoes"
			tabindex="-1"
			onclick={(e) => e.stopPropagation()}
			onkeydown={(e) => e.key === 'Escape' && fecharPermissoes()}
		>
			<h3 id="titulo-permissoes">Permissões de {editando.name}</h3>
			<p class="text-muted">Marque as telas do painel que esta pessoa pode ver e usar.</p>

			{#if carregandoPermissoes}
				<p class="text-muted">Carregando...</p>
			{:else}
				<div class="perm-atalhos">
					<button class="atalho" onclick={ativarTudo} disabled={salvandoPermissoes}>Ativar tudo</button>
					<button class="atalho" onclick={desativarTudo} disabled={salvandoPermissoes}>Desativar tudo</button>
				</div>

				<ul class="perm-lista">
					{#each catalogo as item}
						<li>
							<label class="perm-item" class:perm-item--travada={travada(item.chave)}>
								<input
									type="checkbox"
									checked={marcadas.includes(item.chave)}
									disabled={travada(item.chave) || salvandoPermissoes}
									onchange={() => alternar(item.chave)}
								/>
								<span>
									<strong>{item.nome}</strong>
									<small>{item.descricao}</small>
									{#if travada(item.chave)}
										<small class="perm-aviso">Você não pode tirar esta de si mesmo.</small>
									{/if}
								</span>
							</label>
						</li>
					{/each}
				</ul>
			{/if}

			{#if erroPermissoes}
				<p class="perm-erro">{erroPermissoes}</p>
			{/if}

			<div class="modal-acoes">
				<button class="cancelar" onclick={fecharPermissoes} disabled={salvandoPermissoes}>Cancelar</button>
				<button class="salvar" onclick={confirmarPermissoes} disabled={salvandoPermissoes || carregandoPermissoes}>
					{salvandoPermissoes ? 'Salvando...' : 'Salvar'}
				</button>
			</div>
		</div>
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

	.badge-painel {
		background: #ecfdf5;
		color: #047857;
		font-size: 0.8rem;
		font-weight: 600;
		padding: 0.15rem 0.5rem;
		border-radius: 6px;
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

	.actions-cell {
		white-space: nowrap;
	}

	.perm-btn {
		background-color: #eff6ff;
		color: #1d4ed8;
		border: 1px solid #93c5fd;
		padding: 0.4rem 0.8rem;
		border-radius: 6px;
		font-size: 0.8rem;
		font-weight: 600;
		cursor: pointer;
		margin-right: 0.4rem;
	}
	.perm-btn:hover {
		background-color: #dbeafe;
	}

	/* Janela de permissões */
	.modal-fundo {
		position: fixed;
		inset: 0;
		background: rgba(15, 23, 42, 0.45);
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 1rem;
		z-index: 50;
	}

	.modal {
		background: #fff;
		border-radius: 12px;
		width: 100%;
		max-width: 560px;
		max-height: 90vh;
		overflow-y: auto;
		padding: 1.5rem;
		box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
	}

	.modal h3 {
		margin: 0 0 0.3rem 0;
		color: #111827;
	}

	.perm-atalhos {
		display: flex;
		gap: 0.5rem;
		margin: 1rem 0 0.75rem;
	}

	.atalho {
		background: #f3f4f6;
		border: 1px solid #d1d5db;
		color: #374151;
		padding: 0.4rem 0.8rem;
		border-radius: 6px;
		font-size: 0.85rem;
		font-weight: 600;
		cursor: pointer;
	}
	.atalho:hover:not(:disabled) {
		background: #e5e7eb;
	}

	.perm-lista {
		list-style: none;
		padding: 0;
		margin: 0;
		display: flex;
		flex-direction: column;
		gap: 0.5rem;
	}

	.perm-item {
		display: flex;
		gap: 0.75rem;
		align-items: flex-start;
		padding: 0.7rem 0.85rem;
		border: 1px solid #e5e7eb;
		border-radius: 8px;
		cursor: pointer;
	}
	.perm-item:hover {
		background: #f9fafb;
	}
	.perm-item input {
		margin-top: 0.2rem;
		width: 18px;
		height: 18px;
		accent-color: #1c8b6b;
		flex-shrink: 0;
	}
	.perm-item span {
		display: flex;
		flex-direction: column;
		gap: 0.15rem;
	}
	.perm-item strong {
		color: #111827;
		font-size: 0.95rem;
	}
	.perm-item small {
		color: #6b7280;
		font-size: 0.82rem;
	}
	.perm-item--travada {
		cursor: not-allowed;
		background: #f9fafb;
	}
	.perm-aviso {
		color: #92400e !important;
	}

	.perm-erro {
		color: #dc2626;
		margin: 0.75rem 0 0;
	}

	.modal-acoes {
		display: flex;
		justify-content: flex-end;
		gap: 0.5rem;
		margin-top: 1.25rem;
	}

	.cancelar,
	.salvar {
		padding: 0.55rem 1.1rem;
		border-radius: 6px;
		font-weight: 600;
		cursor: pointer;
		font-size: 0.9rem;
	}
	.cancelar {
		background: #fff;
		border: 1px solid #d1d5db;
		color: #374151;
	}
	.salvar {
		background: #1c8b6b;
		border: 1px solid #1c8b6b;
		color: #fff;
	}
	.salvar:disabled,
	.cancelar:disabled {
		opacity: 0.6;
		cursor: not-allowed;
	}
</style>
