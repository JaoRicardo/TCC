<script lang="ts">
	import { onMount } from 'svelte';
	import { page } from '$app/stores';
	import { apiFetch } from '$lib/api';

	const activityId = $page.params.activityId;

	let subscriptions: any[] = $state([]);
	let activity: any = $state(null);
	let sorteio: { campistas: any; servos: any } | null = $state(null);
	let selectionMethods: any[] = $state([]);
	let loading = $state(true);
	let error = $state('');

	let activeTab = $state('inscritos'); // 'inscritos' | 'sorteio'
	let isBusy = $state(false);

	let showIndicateModal = $state(false);
	let selectedSubToIndicate = $state<any>(null);
	let selectedMethodId = $state<number | null>(null);

	// Draw-form state for campistas (prefilled once the activity loads)
	let vagasHomem = $state(0);
	let vagasMulher = $state(0);
	let vagasCasal = $state(0);

	async function loadActivity() {
		const res = await apiFetch(`/activities/${activityId}`);
		const data = await res.json();
		if (res.ok) {
			activity = data.data;
			vagasHomem = activity.vagasSorteioHomem ?? 0;
			vagasMulher = activity.vagasSorteioMulher ?? 0;
			vagasCasal = activity.vagasSorteioCasal ?? 0;
		} else {
			error = data.error || 'Não foi possível carregar a atividade.';
		}
	}

	async function loadSubscriptions() {
		const res = await apiFetch(`/activities/${activityId}/subscriptions`);
		const data = await res.json();
		if (res.ok) {
			subscriptions = data.subscriptions || [];
		} else {
			error = data.error || 'Erro ao carregar os inscritos.';
		}
	}

	async function loadSorteio() {
		if (!activity || activity.tipo !== 'Acampamento') return;
		const res = await apiFetch(`/activities/${activityId}/sorteio`);
		const data = await res.json();
		if (res.ok) {
			sorteio = { campistas: data.campistas, servos: data.servos };
		}
	}

	async function loadSelectionMethods() {
		const res = await apiFetch('/admin/selection-methods?type=Acampamento');
		const data = await res.json();
		if (res.ok) {
			selectionMethods = (data.data || []).filter((m: any) => m.method !== 'Sorteio');
		}
	}

	onMount(async () => {
		try {
			await loadActivity();
			await Promise.all([loadSubscriptions(), loadSorteio(), loadSelectionMethods()]);
		} catch (e) {
			error = 'Falha de comunicação com o servidor.';
		} finally {
			loading = false;
		}
	});

	async function refreshAfterAction() {
		await Promise.all([loadSubscriptions(), loadSorteio()]);
	}

	async function removeIndication(preRegId: number, nome: string) {
		if (!confirm(`Remover a indicação de ${nome}? A vaga garantida dela será desfeita (e a do cônjuge, se houver).`)) return;
		isBusy = true;
		try {
			const res = await apiFetch(`/inscricoes/${preRegId}/indicacao`, { method: 'DELETE' });
			const data = await res.json();
			if (res.ok) {
				await refreshAfterAction();
			} else {
				alert(data.error || 'Erro ao remover indicação.');
			}
		} catch (e) {
			alert('Erro de comunicação.');
		} finally {
			isBusy = false;
		}
	}

	function calculateAge(birthdayString: string) {
		if (!birthdayString) return '-';
		const parts = birthdayString.split('/');
		if (parts.length !== 3) return '-';
		const birthDate = new Date(Number(parts[2]), Number(parts[1]) - 1, Number(parts[0]));
		const today = new Date();
		let age = today.getFullYear() - birthDate.getFullYear();
		const m = today.getMonth() - birthDate.getMonth();
		if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) age--;
		return age + ' anos';
	}

	function formatDate(dateString?: string | null) {
		if (!dateString) return 'A definir';
		return new Date(dateString).toLocaleString('pt-BR', {
			day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
		}).replace(',', ' às');
	}

	function statusBadgeClass(status: string) {
		if (status === 'Sorteado' || status === 'Indicado') return 'badge-drawn';
		if (status === 'Formulário Enviado') return 'badge-form';
		if (status === 'Suplente') return 'badge-reserve';
		// Perdeu o prazo do formulário: a vaga já foi repassada a um suplente.
		if (status === 'Desistência' || status === 'Prazo Perdido') return 'badge-cancelled';
		return 'badge-waiting';
	}

	async function performRaffleCampistas() {
		if (!confirm('Atenção! Isso vai (re)sortear os campistas com as vagas informadas. Continuar?')) return;
		isBusy = true;
		try {
			const res = await apiFetch(`/activities/${activityId}/sorteio/campistas`, {
				method: 'POST',
				body: JSON.stringify({ homem: vagasHomem, mulher: vagasMulher, casado: vagasCasal })
			});
			const data = await res.json();
			if (res.ok) {
				await refreshAfterAction();
			} else {
				alert(data.error || 'Erro ao realizar o sorteio.');
			}
		} catch (e) {
			alert('Erro de comunicação.');
		} finally {
			isBusy = false;
		}
	}

	async function resetRaffleCampistas() {
		if (!confirm('Isso vai apagar o resultado atual do sorteio de campistas. Continuar?')) return;
		isBusy = true;
		try {
			await apiFetch(`/activities/${activityId}/sorteio/campistas`, { method: 'DELETE' });
			await refreshAfterAction();
		} catch (e) {
			alert('Erro de comunicação.');
		} finally {
			isBusy = false;
		}
	}

	async function performRaffleServos() {
		if (!confirm('Atenção! Isso vai (re)sortear os servos por setor. Continuar?')) return;
		isBusy = true;
		try {
			const res = await apiFetch(`/activities/${activityId}/sorteio/servos`, { method: 'POST' });
			const data = await res.json();
			if (res.ok) {
				await refreshAfterAction();
			} else {
				alert(data.error || 'Erro ao realizar o sorteio.');
			}
		} catch (e) {
			alert('Erro de comunicação.');
		} finally {
			isBusy = false;
		}
	}

	async function resetRaffleServos() {
		if (!confirm('Isso vai apagar o resultado atual do sorteio de servos. Continuar?')) return;
		isBusy = true;
		try {
			await apiFetch(`/activities/${activityId}/sorteio/servos`, { method: 'DELETE' });
			await refreshAfterAction();
		} catch (e) {
			alert('Erro de comunicação.');
		} finally {
			isBusy = false;
		}
	}

	async function submitIndication() {
		if (!selectedSubToIndicate) return alert('Selecione um inscrito!');
		if (!selectedMethodId) return alert('Selecione o motivo da indicação!');
		isBusy = true;
		try {
			const res = await apiFetch(`/activities/${activityId}/indicate`, {
				method: 'POST',
				body: JSON.stringify({
					pre_registration_id: selectedSubToIndicate.id,
					selection_method_id: selectedMethodId
				})
			});
			const data = await res.json();
			if (res.ok) {
				showIndicateModal = false;
				selectedSubToIndicate = null;
				selectedMethodId = null;
				await refreshAfterAction();
				if (data.conjuge_indicado) {
					alert('Indicação salva! O(a) cônjuge vinculado(a) também foi indicado(a) automaticamente.');
				}
			} else {
				alert(data.error || 'Erro ao indicar.');
			}
		} catch (e) {
			alert('Erro de comunicação.');
		} finally {
			isBusy = false;
		}
	}

	let pendingSubscriptions = $derived(subscriptions.filter((s) => s.status === 'Inscrito'));
	// Quem ainda pode ser indicado: pendentes e suplentes (indicar "resgata" um
	// suplente para vaga garantida). Sorteados/Indicados/Desistências ficam de fora.
	let indicatableSubscriptions = $derived(
		subscriptions.filter((s) => s.status === 'Inscrito' || s.status === 'Suplente'),
	);

	function openIndicateModalFor(sub: any | null) {
		selectedSubToIndicate = sub;
		selectedMethodId = null;
		showIndicateModal = true;
	}
</script>

<div class="page-header">
	<div>
		<h2>Inscritos e Sorteios</h2>
		{#if activity}
			<p class="text-muted">Visualizando: <strong>{activity.nome}</strong></p>
		{:else}
			<p class="text-muted">Visualizando inscritos da atividade selecionada.</p>
		{/if}
	</div>
	<div class="header-actions">
		{#if activity?.tipo === 'Acampamento'}
			<button class="btn-indicate" onclick={() => openIndicateModalFor(null)}>
				⭐️ Indicar
			</button>
		{/if}
		<a href="/admin/atividades" class="btn-secondary">Voltar para Atividades</a>
	</div>
</div>

{#if error}
	<div class="error-box"><p>{error}</p></div>
{/if}

{#if loading}
	<div class="loading-state">
		<div class="spinner"></div>
		<p>Carregando inscritos...</p>
	</div>
{:else}
	<!-- Stats -->
	<div class="stats-row">
		<div class="stat-card">
			<span class="stat-title">Total de Inscritos</span>
			<span class="stat-value">{subscriptions.length}</span>
		</div>
		<div class="stat-card">
			<span class="stat-title">Campistas</span>
			<span class="stat-value">{subscriptions.filter((s) => s.subscription_type === 'Campista').length}</span>
		</div>
		<div class="stat-card">
			<span class="stat-title">Servos</span>
			<span class="stat-value">{subscriptions.filter((s) => s.subscription_type === 'Servo').length}</span>
		</div>
		<div class="stat-card">
			<span class="stat-title">Aguardando Decisão</span>
			<span class="stat-value">{pendingSubscriptions.length}</span>
		</div>
	</div>

	{#if activity?.tipo === 'Acampamento'}
		<div class="tabs">
			<button class="tab-btn {activeTab === 'inscritos' ? 'active' : ''}" onclick={() => (activeTab = 'inscritos')}>
				Todos Inscritos
			</button>
			<button class="tab-btn {activeTab === 'sorteio' ? 'active' : ''}" onclick={() => (activeTab = 'sorteio')}>
				Resultado do Sorteio
			</button>
		</div>
	{/if}

	{#if activeTab === 'inscritos' || activity?.tipo !== 'Acampamento'}
		<div class="table-container">
			<table class="data-table">
				<thead>
					<tr>
						<th>Nome</th>
						<th>Data Nasc. / Idade</th>
						<th>CPF</th>
						<th>Gênero</th>
						<th>Estado Civil</th>
						<th>Cônjuge (CPF)</th>
						<th>Tipo</th>
						<th>Inscrição</th>
						<th class="center">Status</th>
					</tr>
				</thead>
				<tbody>
					{#if subscriptions.length === 0}
						<tr><td colspan="9" class="text-center py-4">Nenhuma inscrição encontrada.</td></tr>
					{/if}
					{#each subscriptions as sub}
						<tr>
							<td class="fw-bold">{sub.user.name}</td>
							<td>
								{sub.user.birthday || '-'} <br />
								<small class="text-muted">({calculateAge(sub.user.birthday)})</small>
							</td>
							<td><span class="mono">{sub.user.cpf}</span></td>
							<td>{sub.user.sex === 'M' ? 'Masculino' : sub.user.sex === 'F' ? 'Feminino' : 'Outro'}</td>
							<td>{sub.user.marital_status}</td>
							<td>
								{#if sub.spouse_cpf}
									<span class="mono badge-spouse">{sub.spouse_cpf}</span>
								{:else}
									<span class="text-muted">-</span>
								{/if}
							</td>
							<td>{sub.subscription_type || '-'}</td>
							<td>{sub.created ? new Date(sub.created).toLocaleDateString('pt-BR') : '-'}</td>
							<td class="center">
								<span class="badge {statusBadgeClass(sub.status)}">{sub.status}</span>
								{#if sub.status === 'Indicado'}
									<button class="btn-remove-ind" onclick={() => removeIndication(sub.id, sub.user.name)} disabled={isBusy} title="Remover indicação">✕</button>
								{:else if sub.status === 'Inscrito' || sub.status === 'Suplente'}
									<button class="btn-row-indicate" onclick={() => openIndicateModalFor(sub)} disabled={isBusy} title="Indicar {sub.user.name}">⭐️ Indicar</button>
								{/if}
							</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	{:else if activeTab === 'sorteio' && sorteio}
		<!-- Campistas -->
		<section class="raffle-card">
			<div class="raffle-card-header">
				<h3>🏕️ Sorteio de Campistas</h3>
				{#if sorteio.campistas.realizado}
					<button class="btn-danger-outline" onclick={resetRaffleCampistas} disabled={isBusy}>Resetar Sorteio</button>
				{/if}
			</div>

			<div class="raffle-counts">
				<span><strong>{sorteio.campistas.inscritos_por_categoria.Homem}</strong> homens inscritos</span>
				<span><strong>{sorteio.campistas.inscritos_por_categoria.Mulher}</strong> mulheres inscritas</span>
				<span><strong>{sorteio.campistas.inscritos_por_categoria.Casado}</strong> casais inscritos</span>
				{#if activity.inicioSorteio}
					<span class="raffle-date">📅 Sorteio oficial: {formatDate(activity.inicioSorteio)}</span>
				{/if}
			</div>

			{#if sorteio.campistas.indicados.length > 0}
				<div class="indicados-box">
					<h4>⭐️ Indicados ({sorteio.campistas.indicados.length}) — vaga garantida, não entram no sorteio</h4>
					<ul class="raffle-list">
						{#each sorteio.campistas.indicados as p}
							<li class="indicado-item">
								<span>{p.nome} <span class="tag-mini">{p.categoria}</span> <span class="tag-mini tag-metodo">{p.metodo}</span></span>
								<button class="btn-remove-ind" onclick={() => removeIndication(p.pre_registration_id, p.nome)} disabled={isBusy} title="Remover indicação">✕</button>
							</li>
						{/each}
					</ul>
				</div>
			{/if}

			<div class="raffle-form">
				<div class="raffle-form-field">
					<label for="vagasHomem">Vagas — Homens</label>
					<input type="number" id="vagasHomem" min="0" bind:value={vagasHomem} />
				</div>
				<div class="raffle-form-field">
					<label for="vagasMulher">Vagas — Mulheres</label>
					<input type="number" id="vagasMulher" min="0" bind:value={vagasMulher} />
				</div>
				<div class="raffle-form-field">
					<label for="vagasCasal">Vagas — Casais</label>
					<input type="number" id="vagasCasal" min="0" bind:value={vagasCasal} />
				</div>
				<button class="btn-primary" onclick={performRaffleCampistas} disabled={isBusy}>
					{sorteio.campistas.realizado ? '🎲 Re-sortear' : '🎲 Realizar Sorteio'}
				</button>
			</div>

			{#if sorteio.campistas.realizado}
				<div class="raffle-results-grid">
					<div class="raffle-result-col">
						<h4>✅ Sorteados ({sorteio.campistas.sorteados.length})</h4>
						{#if sorteio.campistas.sorteados.length === 0}
							<p class="text-muted">Ninguém sorteado nesta categoria.</p>
						{/if}
						<ul class="raffle-list">
							{#each sorteio.campistas.sorteados as p}
								<li>
									<span class="raffle-pos">#{p.posicao}</span> {p.nome}
									<span class="tag-mini">{p.categoria}</span>
									{#if p.promovido}<span class="tag-form">↑ da suplência</span>{/if}
								</li>
							{/each}
						</ul>
					</div>
					<div class="raffle-result-col">
						<h4>⏳ Suplentes ({sorteio.campistas.suplentes.length})</h4>
						{#if sorteio.campistas.suplentes.length === 0}
							<p class="text-muted">Sem suplentes.</p>
						{:else}
							<!-- Suplente só pode ser chamado para uma vaga liberada se já
							     tiver entregado o formulário dentro do prazo. -->
							<p class="text-muted mini-empty">Só é chamado quem já entregou o formulário.</p>
						{/if}
						<ul class="raffle-list">
							{#each sorteio.campistas.suplentes as p}
								<li>
									<span class="raffle-pos">#{p.posicao}</span> {p.nome}
									<span class="tag-mini">{p.categoria}</span>
									<span class="tag-form" class:tag-form--falta={!p.formularioEnviado}>
										{p.formularioEnviado ? '✓ formulário' : 'sem formulário'}
									</span>
								</li>
							{/each}
						</ul>
					</div>
					<div class="raffle-result-col">
						<h4>❌ Não Sorteados ({sorteio.campistas.nao_sorteados.length})</h4>
						{#if sorteio.campistas.nao_sorteados.length === 0}
							<p class="text-muted">Todos foram contemplados.</p>
						{/if}
						<ul class="raffle-list muted-list">
							{#each sorteio.campistas.nao_sorteados as p}
								<li>{p.nome} <span class="tag-mini">{p.categoria}</span></li>
							{/each}
						</ul>
					</div>
				</div>

				<!-- Quem foi selecionado e perdeu a vaga por não enviar o formulário
				     até o prazo. A vaga já foi repassada ao suplente da vez. -->
				{#if (sorteio.campistas.desclassificados?.length ?? 0) > 0}
					<div class="raffle-disqualified">
						<h4>⏳ Perderam o prazo do formulário ({sorteio.campistas.desclassificados.length})</h4>
						<p class="text-muted">
							Titular que não entregou teve a vaga oferecida ao próximo suplente com formulário
							enviado; suplente que não entregou saiu da fila.
						</p>
						<ul class="raffle-list muted-list">
							{#each sorteio.campistas.desclassificados as p}
								<li>
									{p.nome}
									<span class="tag-mini">{p.categoria}</span>
									<span class="text-muted">— {formatDate(p.desclassificado_em)}</span>
								</li>
							{/each}
						</ul>
					</div>
				{/if}
			{/if}
		</section>

		<!-- Servos -->
		<section class="raffle-card">
			<div class="raffle-card-header">
				<h3>🛠️ Sorteio de Servos</h3>
				{#if sorteio.servos.realizado}
					<button class="btn-danger-outline" onclick={resetRaffleServos} disabled={isBusy}>Resetar Sorteio</button>
				{/if}
			</div>

			<div class="raffle-counts">
				<span><strong>{sorteio.servos.total_inscritos}</strong> servos inscritos ao todo</span>
				{#if activity.inicioSorteioServo}
					<span class="raffle-date">📅 Sorteio oficial: {formatDate(activity.inicioSorteioServo)}</span>
				{/if}
			</div>

			{#if sorteio.servos.indicados.length > 0}
				<div class="indicados-box">
					<h4>⭐️ Indicados ({sorteio.servos.indicados.length}) — vaga garantida, não entram no sorteio</h4>
					<ul class="raffle-list">
						{#each sorteio.servos.indicados as p}
							<li class="indicado-item">
								<span>{p.nome} <span class="tag-mini tag-metodo">{p.metodo}</span></span>
								<button class="btn-remove-ind" onclick={() => removeIndication(p.pre_registration_id, p.nome)} disabled={isBusy} title="Remover indicação">✕</button>
							</li>
						{/each}
					</ul>
				</div>
			{/if}

			{#if sorteio.servos.alocados_manualmente?.length > 0}
				<div class="indicados-box">
					<h4>🧩 Já alocados numa equipe ({sorteio.servos.alocados_manualmente.length}) — vaga garantida, não entram no sorteio</h4>
					<ul class="raffle-list">
						{#each sorteio.servos.alocados_manualmente as p}
							<li class="indicado-item">
								<span>{p.nome} <span class="tag-mini tag-metodo">{p.setor}</span></span>
							</li>
						{/each}
					</ul>
					<p class="text-muted" style="margin: 0.5rem 0 0;">Para mudar, use "Gerenciar Equipe de Servos" na lista de atividades.</p>
				</div>
			{/if}

			{#if sorteio.servos.servo_geral?.length > 0}
				<div class="indicados-box">
					<h4>👑 Servo geral ({sorteio.servos.servo_geral.length}) — não entra no sorteio de equipe</h4>
					<ul class="raffle-list">
						{#each sorteio.servos.servo_geral as p}
							<li class="indicado-item"><span>{p.nome}</span></li>
						{/each}
					</ul>
				</div>
			{/if}

			{#if sorteio.servos.setores.length === 0}
				<p class="text-muted">Nenhum setor com vagas de sorteio configurado para esta atividade.</p>
			{:else}
				<div class="raffle-form">
					<button class="btn-primary" onclick={performRaffleServos} disabled={isBusy}>
						{sorteio.servos.realizado ? '🎲 Re-sortear Servos' : '🎲 Realizar Sorteio de Servos'}
					</button>
				</div>

				<div class="sector-grid">
					{#each sorteio.servos.setores as setor}
						<div class="sector-card">
							<h4>{setor.sector_name} <span class="text-muted">({setor.vacancies} {setor.vacancies === 1 ? 'vaga' : 'vagas'})</span></h4>
							<div class="raffle-result-col">
								<strong class="mini-title">✅ Sorteados ({setor.sorteados.length})</strong>
								{#if setor.sorteados.length === 0}
									<p class="text-muted mini-empty">Ninguém sorteado ainda.</p>
								{/if}
								<ul class="raffle-list">
									{#each setor.sorteados as p}
										<li><span class="raffle-pos">#{p.posicao}</span> {p.nome}</li>
									{/each}
								</ul>
							</div>
							<div class="raffle-result-col">
								<strong class="mini-title">⏳ Suplentes ({setor.suplentes.length})</strong>
								{#if setor.suplentes.length === 0}
									<p class="text-muted mini-empty">Sem suplentes.</p>
								{/if}
								<ul class="raffle-list">
									{#each setor.suplentes as p}
										<li>
											<span class="raffle-pos">#{p.posicao}</span> {p.nome}
											<span class="tag-form" class:tag-form--falta={!p.formularioEnviado}>
												{p.formularioEnviado ? '✓ formulário' : 'sem formulário'}
											</span>
										</li>
									{/each}
								</ul>
							</div>
						</div>
					{/each}
				</div>

				{#if sorteio.servos.realizado && sorteio.servos.nao_sorteados.length > 0}
					<div class="raffle-result-col mt-2">
						<h4>❌ Não Sorteados ({sorteio.servos.nao_sorteados.length})</h4>
						<ul class="raffle-list muted-list">
							{#each sorteio.servos.nao_sorteados as p}
								<li>{p.nome}</li>
							{/each}
						</ul>
					</div>
				{/if}
			{/if}
		
			<!-- Servos que perderam a vaga por não enviar o formulário no prazo. -->
			{#if (sorteio.servos.desclassificados?.length ?? 0) > 0}
				<div class="raffle-disqualified">
					<h4>⏳ Perderam o prazo do formulário ({sorteio.servos.desclassificados.length})</h4>
					<p class="text-muted">
						Titular que não entregou teve a vaga oferecida ao próximo suplente do setor com
						formulário enviado; suplente que não entregou saiu da fila.
					</p>
					<ul class="raffle-list muted-list">
						{#each sorteio.servos.desclassificados as p}
							<li>{p.nome} <span class="text-muted">— {formatDate(p.desclassificado_em)}</span></li>
						{/each}
					</ul>
				</div>
			{/if}
		</section>
	{/if}
{/if}

<!-- Modal de Indicação -->
{#if showIndicateModal}
	<div class="modal-backdrop">
		<div class="modal-content">
			<h3>Indicar Vaga</h3>
			<p class="text-muted mb-2">Selecione um inscrito pendente ou suplente para garantir a vaga dele fora do sorteio.</p>

			<div class="form-group">
				<label for="indicate-sub">Inscrito</label>
				<select id="indicate-sub" bind:value={selectedSubToIndicate} class="form-select">
					<option value={null}>Selecione um inscrito...</option>
					{#each indicatableSubscriptions as sub}
						<option value={sub}>{sub.user.name} ({sub.subscription_type}{sub.status === 'Suplente' ? ', suplente' : ''}) — {sub.user.cpf}</option>
					{/each}
				</select>
			</div>

			<div class="form-group">
				<label for="indicate-method">Motivo da Indicação</label>
				<select id="indicate-method" bind:value={selectedMethodId} class="form-select">
					<option value={null}>Selecione...</option>
					{#each selectionMethods as method}
						<option value={method.id}>{method.method} — {method.description}</option>
					{/each}
				</select>
			</div>

			<div class="modal-actions">
				<button class="btn-secondary" onclick={() => { showIndicateModal = false; selectedMethodId = null; }}>Cancelar</button>
				<button class="btn-primary" onclick={submitIndication} disabled={isBusy}>Confirmar Indicação</button>
			</div>
		</div>
	</div>
{/if}

<style>
	.page-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 2rem;
		flex-wrap: wrap;
		gap: 1rem;
	}

	.header-actions {
		display: flex;
		gap: 1rem;
		align-items: center;
	}

	.page-header h2 {
		margin: 0 0 0.5rem 0;
		font-size: 1.8rem;
		color: #111827;
	}

	.text-muted { color: #64748b; }
	.mb-2 { margin-bottom: 0.5rem; display: inline-block; }
	.mt-2 { margin-top: 1.5rem; }
	.py-4 { padding: 2rem 0 !important; }
	.text-center { text-align: center; }

	/* Tabs */
	.tabs {
		display: flex;
		gap: 1rem;
		margin-bottom: 1.5rem;
		border-bottom: 2px solid #e2e8f0;
	}

	.tab-btn {
		background: none;
		border: none;
		padding: 0.75rem 1.5rem;
		font-size: 1rem;
		font-weight: 600;
		color: #64748b;
		cursor: pointer;
		position: relative;
		transition: all 0.2s;
	}

	.tab-btn:hover { color: #0f172a; }
	.tab-btn.active { color: #3b82f6; }
	.tab-btn.active::after {
		content: '';
		position: absolute;
		bottom: -2px; left: 0; right: 0;
		height: 2px;
		background: #3b82f6;
	}

	/* Buttons */
	.btn-primary {
		background: #3b82f6;
		color: white;
		border: none;
		padding: 0.6rem 1.4rem;
		border-radius: 8px;
		font-weight: 600;
		cursor: pointer;
		transition: background 0.2s;
	}
	.btn-primary:hover:not(:disabled) { background: #2563eb; }
	.btn-primary:disabled { background: #94a3b8; cursor: not-allowed; }

	.btn-secondary {
		padding: 0.5rem 1.25rem;
		background: #f1f5f9;
		color: #334155;
		border: 1px solid #cbd5e1;
		border-radius: 8px;
		text-decoration: none;
		font-weight: 600;
		transition: all 0.2s;
	}
	.btn-secondary:hover { background: #e2e8f0; }

	.btn-danger-outline {
		background: #fff;
		color: #dc2626;
		border: 1px solid #fecaca;
		padding: 0.5rem 1rem;
		border-radius: 8px;
		font-weight: 600;
		cursor: pointer;
		transition: all 0.2s;
	}
	.btn-danger-outline:hover:not(:disabled) { background: #fef2f2; }
	.btn-danger-outline:disabled { opacity: 0.6; cursor: not-allowed; }

	.btn-indicate {
		display: inline-flex;
		align-items: center;
		gap: 0.4rem;
		background: #fffbeb;
		color: #d97706;
		border: 1px solid #fde68a;
		padding: 0.5rem 1rem;
		border-radius: 9999px;
		font-size: 0.85rem;
		font-weight: 600;
		cursor: pointer;
		transition: all 0.2s;
		white-space: nowrap;
	}
	.btn-indicate:hover {
		background: #fef3c7;
		border-color: #fcd34d;
		transform: translateY(-1px);
	}

	.stats-row {
		display: flex;
		gap: 1.5rem;
		margin-bottom: 2rem;
		flex-wrap: wrap;
	}

	.stat-card {
		background: #fff;
		flex: 1;
		min-width: 160px;
		padding: 1.5rem;
		border-radius: 12px;
		border: 1px solid #e5e7eb;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05);
		display: flex;
		flex-direction: column;
	}

	.stat-title {
		font-size: 0.85rem;
		color: #6b7280;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		font-weight: 600;
		margin-bottom: 0.5rem;
	}

	.stat-value { font-size: 2rem; font-weight: 800; color: #111827; }

	.table-container {
		background: #fff;
		border-radius: 12px;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05);
		border: 1px solid #e5e7eb;
		overflow-x: auto;
	}

	.data-table { width: 100%; border-collapse: collapse; text-align: left; }

	.data-table th {
		background: #f9fafb;
		padding: 1rem 1.5rem;
		font-size: 0.75rem;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		color: #6b7280;
		font-weight: 600;
		border-bottom: 1px solid #e5e7eb;
		white-space: nowrap;
	}

	.data-table td {
		padding: 1rem 1.5rem;
		border-bottom: 1px solid #f3f4f6;
		color: #374151;
		font-size: 0.95rem;
		vertical-align: middle;
	}

	.data-table tr:last-child td { border-bottom: none; }
	.data-table tr:hover td { background-color: #f8fafc; }
	.center { text-align: center; }

	.fw-bold { font-weight: 600; color: #111827; }
	.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }

	.badge-spouse {
		background: #e0e7ff;
		color: #3730a3;
		padding: 0.2rem 0.6rem;
		border-radius: 9999px;
		font-size: 0.85rem;
		font-weight: 600;
	}

	.loading-state, .error-box {
		background: #fff;
		border-radius: 12px;
		padding: 4rem 2rem;
		text-align: center;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05);
		border: 1px solid #e5e7eb;
	}
	.error-box { color: #dc2626; padding: 1.5rem; }
	.spinner {
		width: 40px; height: 40px;
		margin: 0 auto 1rem;
		border: 4px solid rgba(28, 139, 107, 0.2);
		border-top-color: #1c8b6b;
		border-radius: 50%;
		animation: spin 1s linear infinite;
	}
	@keyframes spin { to { transform: rotate(360deg); } }

	/* Badges */
	.badge {
		padding: 0.3rem 0.65rem;
		border-radius: 12px;
		font-size: 0.75rem;
		font-weight: 700;
		text-transform: uppercase;
		white-space: nowrap;
	}

	.badge-waiting { background: #f1f5f9; color: #64748b; }
	.badge-drawn { background: #dcfce7; color: #166534; }
	.badge-reserve { background: #ffedd5; color: #9a3412; }
	.badge-form { background: #cffafe; color: #155e75; }
	.badge-cancelled { background: #fee2e2; color: #991b1b; }

	.btn-remove-ind {
		margin-left: 0.5rem;
		width: 1.4rem;
		height: 1.4rem;
		border-radius: 50%;
		border: 1px solid #fecaca;
		background: #fff;
		color: #dc2626;
		font-size: 0.7rem;
		line-height: 1;
		cursor: pointer;
		transition: all 0.15s;
	}
	.btn-remove-ind:hover:not(:disabled) { background: #fef2f2; border-color: #f87171; }
	.btn-remove-ind:disabled { opacity: 0.5; cursor: not-allowed; }

	.btn-row-indicate {
		display: inline-flex;
		align-items: center;
		gap: 0.3rem;
		background: #fffbeb;
		color: #d97706;
		border: 1px solid #fde68a;
		padding: 0.25rem 0.65rem;
		border-radius: 9999px;
		font-size: 0.78rem;
		font-weight: 600;
		cursor: pointer;
		white-space: nowrap;
		transition: all 0.15s;
	}
	.btn-row-indicate:hover:not(:disabled) {
		background: #fef3c7;
		border-color: #fcd34d;
	}
	.btn-row-indicate:disabled { opacity: 0.5; cursor: not-allowed; }

	/* Raffle cards */
	.raffle-card {
		background: #fff;
		border-radius: 12px;
		border: 1px solid #e5e7eb;
		box-shadow: 0 1px 3px rgba(0,0,0,0.05);
		padding: 1.75rem;
		margin-bottom: 1.75rem;
	}

	.raffle-card-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 1rem;
	}

	.raffle-card-header h3 { margin: 0; color: #0f172a; font-size: 1.2rem; }

	.raffle-counts {
		display: flex;
		flex-wrap: wrap;
		gap: 1.25rem;
		color: #475569;
		font-size: 0.92rem;
		margin-bottom: 1.25rem;
		padding-bottom: 1.25rem;
		border-bottom: 1px dashed #e2e8f0;
	}

	.raffle-date { margin-left: auto; color: #64748b; }

	.raffle-form {
		display: flex;
		align-items: flex-end;
		gap: 1.25rem;
		flex-wrap: wrap;
		margin-bottom: 1.5rem;
	}

	.raffle-form-field { display: flex; flex-direction: column; gap: 0.4rem; }
	.raffle-form-field label { font-size: 0.8rem; font-weight: 600; color: #475569; }
	.raffle-form-field input {
		width: 100px;
		padding: 0.55rem 0.75rem;
		border: 1px solid #cbd5e1;
		border-radius: 6px;
		font-size: 0.95rem;
	}

	.raffle-results-grid {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 1.5rem;
	}

	@media (max-width: 900px) {
		.raffle-results-grid { grid-template-columns: 1fr; }
	}

	.raffle-result-col h4 { margin: 0 0 0.75rem 0; color: #0f172a; font-size: 1rem; }
	.mini-title { display: block; margin-bottom: 0.5rem; color: #0f172a; font-size: 0.88rem; }
	.mini-empty { font-size: 0.82rem; margin: 0 0 0.75rem 0; }

	.tag-form {
		font-size: 0.68rem;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.03em;
		padding: 0.1rem 0.45rem;
		border-radius: 999px;
		background: #ecfdf5;
		color: #065f46;
		border: 1px solid #a7f3d0;
		white-space: nowrap;
	}

	.tag-form--falta {
		background: #fff7ed;
		color: #9a3412;
		border-color: #fed7aa;
	}

	.raffle-disqualified {
		margin-top: 1.2rem;
		padding: 1rem 1.2rem;
		border: 1px solid #fed7aa;
		background: #fff7ed;
		border-radius: 10px;
	}

	.raffle-disqualified h4 {
		margin: 0 0 0.3rem;
		color: #9a3412;
	}

	.raffle-list {
		list-style: none;
		padding: 0;
		margin: 0;
		display: flex;
		flex-direction: column;
		gap: 0.5rem;
		max-height: 300px;
		overflow-y: auto;
	}

	.raffle-list li {
		background: #f8fafc;
		border: 1px solid #eef2f7;
		border-radius: 8px;
		padding: 0.55rem 0.75rem;
		font-size: 0.88rem;
		color: #334155;
	}

	.raffle-list.muted-list li { background: #fff; border-style: dashed; color: #64748b; }

	.raffle-pos {
		display: inline-block;
		background: #e0f2fe;
		color: #0369a1;
		font-weight: 700;
		font-size: 0.75rem;
		padding: 0.1rem 0.4rem;
		border-radius: 6px;
		margin-right: 0.4rem;
	}

	.tag-mini {
		display: inline-block;
		background: #f1f5f9;
		color: #64748b;
		font-size: 0.72rem;
		padding: 0.1rem 0.4rem;
		border-radius: 6px;
		margin-left: 0.3rem;
	}

	.tag-metodo {
		background: #fef3c7;
		color: #92400e;
		font-weight: 600;
	}

	.indicados-box {
		background: #fffbeb;
		border: 1px solid #fde68a;
		border-radius: 10px;
		padding: 1.25rem 1.5rem;
		margin-bottom: 1.5rem;
	}

	.indicados-box h4 {
		margin: 0 0 0.85rem 0;
		color: #92400e;
		font-size: 0.92rem;
	}

	.indicado-item {
		display: flex;
		justify-content: space-between;
		align-items: center;
		background: #fff !important;
	}

	.sector-grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
		gap: 1.25rem;
	}

	.sector-card {
		background: #f8fafc;
		border: 1px solid #e2e8f0;
		border-radius: 10px;
		padding: 1.25rem;
	}

	.sector-card h4 { margin: 0 0 1rem 0; color: #0f172a; font-size: 1rem; }

	/* Modal */
	.modal-backdrop {
		position: fixed;
		top: 0; left: 0; right: 0; bottom: 0;
		background: rgba(0,0,0,0.5);
		display: flex;
		align-items: center;
		justify-content: center;
		z-index: 1000;
	}

	.modal-content {
		background: white;
		padding: 2rem;
		border-radius: 12px;
		width: 440px;
		max-width: 90%;
		box-shadow: 0 10px 25px rgba(0,0,0,0.2);
	}

	.modal-content h3 { margin-top: 0; color: #0f172a; }

	.form-group { margin: 1.5rem 0; }
	.form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: #334155; }
	.form-select {
		width: 100%;
		padding: 0.6rem;
		border: 1px solid #cbd5e1;
		border-radius: 6px;
		font-size: 0.95rem;
	}

	.modal-actions {
		display: flex;
		justify-content: flex-end;
		gap: 1rem;
		margin-top: 2rem;
	}
</style>
