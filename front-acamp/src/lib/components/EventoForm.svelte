<script lang="ts">
	/**
	 * Cadastro de evento, usado por Criar Evento e Editar Evento. Na edição,
	 * carrega de GET /activities/{id}/edit e salva com _method=PATCH.
	 *
	 * "Formulário de inscrição": com formulário, a inscrição só conclui quando a
	 * pessoa envia (e a vaga só fica garantida no envio); "Nenhum" conclui na
	 * hora. "Fim das inscrições" é o prazo para se inscrever e enviar.
	 */
	import { onMount } from 'svelte';
	import { apiFetch } from '$lib/api';
	import { getFormulariosDeEvento, type FormularioDeEvento } from '$lib/services/perguntaService';
	import { pode } from '$lib/permissoes';
	import '$lib/styles/admin-form.css';

	let { activityId = null }: { activityId?: string | null } = $props();
	let editando = $derived(activityId !== null);

	let loading = $state(false);
	let carregandoEvento = $state(false);
	let error = $state('');
	let success = $state(false);
	let activeTab = $state('basic');

	// ─── Formulário de inscrição ─────────────────────────────────────────
	let formularios: FormularioDeEvento[] = $state([]);
	let carregandoFormularios = $state(false);
	/** Formulário que o evento tinha ao abrir a edição (para avisar sobre a troca). */
	let formularioOriginal = $state('');

	async function carregarFormularios() {
		carregandoFormularios = true;
		try {
			const res = await getFormulariosDeEvento();
			formularios = res.data ?? [];
		} catch {
			formularios = [];
		} finally {
			carregandoFormularios = false;
		}
	}

	/** "2026-11-20T23:59:00" → valor de <input type="datetime-local">. */
	const paraCampo = (v: unknown) => (typeof v === 'string' && v ? v.slice(0, 16) : '');

	async function carregarEvento() {
		carregandoEvento = true;
		try {
			const res = await apiFetch(`/activities/${activityId}/edit`);
			const data = await res.json();
			const a = data.data?.activity;
			if (!res.ok || !a) {
				error = data.error || 'Não foi possível carregar o evento.';
				return;
			}
			const fee = Number(a.participant_fee ?? 0);
			formData.name = a.name ?? '';
			formData.place = a.place ?? '';
			formData.start_date = paraCampo(a.start_date);
			formData.duration_days = Number(a.duration_days ?? 1);
			formData.total_vacancies = Number(a.total_vacancies ?? 1);
			formData.min_age = a.min_age ?? '';
			formData.max_age = a.max_age ?? '';
			formData.target_audience = a.target_audience ?? 'Geral';
			formData.is_paid_festival = fee > 0;
			formData.ticket_price = fee;
			formData.sale_start_date = paraCampo(a.participant_registration_start_date);
			formData.payment_link = a.participant_payment_link ?? '';
			formData.registration_end_date = paraCampo(a.participant_registration_end_date);
			formData.event_form_id = a.event_form_id ? String(a.event_form_id) : '';
			formularioOriginal = formData.event_form_id;
		} catch {
			error = 'Não foi possível carregar o evento. Verifique sua conexão.';
		} finally {
			carregandoEvento = false;
		}
	}

	onMount(() => {
		carregarFormularios();
		if (editando) carregarEvento();
	});

	const tabs = ['basic', 'audience', 'schedule', 'finance'];
	const tabLabels: Record<string, string> = {
		basic: '📍 Dados Básicos',
		audience: '🎯 Público-Alvo',
		schedule: '📅 Cronograma',
		finance: '🎟️ Ingressos',
	};
	let progressPct = $derived(((tabs.indexOf(activeTab) + 1) / tabs.length) * 100);

	function nextTab() {
		const visibleInputs = document.querySelectorAll('.af-section[style*="display: block"] input[required], .af-section[style*="display: block"] select[required]');
		let isValid = true;
		visibleInputs.forEach((input: any) => {
			if (!input.checkValidity()) {
				input.reportValidity();
				isValid = false;
			}
		});

		if (isValid) {
			const idx = tabs.indexOf(activeTab);
			if (idx < tabs.length - 1) {
				activeTab = tabs[idx + 1];
			}
		}
	}

	function prevTab() {
		const idx = tabs.indexOf(activeTab);
		if (idx > 0) {
			activeTab = tabs[idx - 1];
		}
	}

	let formData = $state({
		name: '',
		place: '',
		image: null as File | null,
		start_date: '',
		duration_days: 1,
		total_vacancies: 100,
		min_age: '',
		max_age: '',
		target_audience: 'Geral',
		is_paid_festival: false,
		ticket_price: 0,
		sale_start_date: '',
		payment_link: '',
		registration_end_date: '',
		/** '' = nenhum formulário. */
		event_form_id: ''
	});

	async function handleSubmit(e: Event) {
		e.preventDefault();
		loading = true;
		error = '';
		success = false;

		const body = new FormData();
		body.append('type', 'Event');

		// Anexar arquivo
		if (formData.image) {
			body.append('activity[image]', formData.image);
		}

		// Anexar campos de texto e números
		body.append('activity[name]', formData.name);
		body.append('activity[place]', formData.place);
		body.append('activity[duration_days]', formData.duration_days.toString());
		body.append('activity[total_vacancies]', formData.total_vacancies.toString());

		if (formData.start_date) body.append('activity[start_date]', formData.start_date);

		if (formData.min_age !== '') body.append('activity[min_age]', formData.min_age.toString());
		if (formData.max_age !== '') body.append('activity[max_age]', formData.max_age.toString());
		body.append('activity[target_audience]', formData.target_audience);

		if (formData.is_paid_festival) {
			body.append('activity[participant_fee]', formData.ticket_price ? formData.ticket_price.toString() : '0');
			if (formData.sale_start_date) body.append('activity[participant_registration_start_date]', formData.sale_start_date);
			if (formData.payment_link) body.append('activity[participant_payment_link]', formData.payment_link);
		} else {
			body.append('activity[participant_fee]', '0');
		}

		if (formData.registration_end_date) body.append('activity[participant_registration_end_date]', formData.registration_end_date);
		// Vazio = nenhum formulário (o servidor entende '' como "sem formulário").
		body.append('activity[event_form_id]', formData.event_form_id);

		// PHP só lê multipart em POST; o _method faz o CakePHP tratar como PATCH.
		if (editando) body.append('_method', 'PATCH');

		try {
			const res = await apiFetch(editando ? `/activities/${activityId}` : '/activities', {
				method: 'POST',
				body: body
			});

			if (!res.ok) {
				const text = await res.text();
				try {
					const data = JSON.parse(text);
					if (data.errors) {
						const firstField = Object.keys(data.errors)[0];
						const firstRule = Object.keys(data.errors[firstField])[0];
						error = `Erro em ${firstField}: ${data.errors[firstField][firstRule]}`;
					} else {
						error = data.error || data.message || 'Erro ao criar evento';
					}
				} catch(e) {
					// Se não for JSON, provavelmente é um erro 500 do servidor (Exceção do CakePHP)
					error = `Erro fatal do servidor (${res.status}): Detalhes no log ou no console do navegador.`;
					console.error("Server Error HTML/Text:", text);
				}
				window.scrollTo(0, 0);
				loading = false;
				return;
			}

			await res.json();
			success = true;
			formularioOriginal = formData.event_form_id;
			window.scrollTo(0, 0);
		} catch (err: any) {
			error = 'Erro na requisição: ' + err.message;
			window.scrollTo(0, 0);
		} finally {
			loading = false;
		}
	}
</script>

<div class="admin-form-page">
	<div class="af-header">
		<div>
			<h2>{editando ? 'Editar Evento' : 'Criar Novo Evento'}</h2>
			<p>
				{editando
					? 'Altere os dados do evento. As mudanças valem na hora para quem for se inscrever.'
					: 'Preencha os dados abaixo para publicar um evento simples (com ou sem ingressos).'}
			</p>
		</div>
		<a href="/dashboard" class="af-back-link">← Voltar</a>
	</div>

	{#if success}
		<div class="af-alert af-alert--success">
			{#if editando}
				<span><strong>Pronto!</strong> As alterações do evento foram salvas.</span>
				<button onclick={() => (success = false)} class="af-alert-close">Fechar</button>
			{:else}
				<span><strong>Sucesso!</strong> O evento foi criado e já está salvo no sistema.</span>
				<button onclick={() => (success = false)} class="af-alert-close">Nova criação</button>
			{/if}
		</div>
	{/if}

	{#if error}
		<div class="af-alert af-alert--error">
			<span><strong>Atenção:</strong> {error}</span>
		</div>
	{/if}

	<div class="af-tabs">
		{#each tabs as t}
			<button type="button" class="af-tab" class:active={activeTab === t} onclick={() => activeTab = t}>{tabLabels[t]}</button>
		{/each}
	</div>
	<div class="af-progress"><div class="af-progress-fill" style="width: {progressPct}%"></div></div>

	{#if carregandoEvento}
		<div class="af-alert af-alert--info"><span>Carregando os dados do evento...</span></div>
	{/if}

	<form class="af-form" onsubmit={handleSubmit} inert={carregandoEvento}>

		<!-- Informações Básicas -->
		<div class="af-section" style="display: {activeTab === 'basic' ? 'block' : 'none'}">
			<div class="af-section-title"><h3>Informações Básicas</h3></div>
			<div class="af-grid-2">
				<div class="af-field">
					<label for="name">Nome do Evento *</label>
					<input type="text" id="name" bind:value={formData.name} required placeholder="Ex: Show de Talentos" />
				</div>
				<div class="af-field">
					<label for="place">Local *</label>
					<input type="text" id="place" bind:value={formData.place} required placeholder="Ex: Quadra Principal" />
				</div>
			</div>
			<div class="af-field af-mt">
				<label for="image">Imagem de Capa {editando ? '(envie só para trocar)' : '*'}</label>
				<input type="file" id="image" accept="image/*" onchange={(e) => formData.image = e.currentTarget.files?.[0] || null} required={!editando} />
			</div>
		</div>

		<!-- Público Alvo -->
		<div class="af-section" style="display: {activeTab === 'audience' ? 'block' : 'none'}">
			<div class="af-section-title"><h3>Público-Alvo</h3></div>
			<div class="af-grid-3">
				<div class="af-field">
					<label for="target_audience">Público *</label>
					<select id="target_audience" bind:value={formData.target_audience} required>
						<option value="Geral">Geral (Todos)</option>
						<option value="Homens">Apenas Homens</option>
						<option value="Mulheres">Apenas Mulheres</option>
						<option value="Casais">Exclusivo para Casais</option>
					</select>
				</div>
				<div class="af-field">
					<label for="min_age">Idade Mínima</label>
					<input type="number" id="min_age" bind:value={formData.min_age} min="0" placeholder="Sem limite" />
				</div>
				<div class="af-field">
					<label for="max_age">Idade Máxima</label>
					<input type="number" id="max_age" bind:value={formData.max_age} min="0" placeholder="Sem limite" />
				</div>
			</div>
			{#if formData.target_audience === 'Casais'}
				<div class="af-alert af-alert--info af-mt">
					<span><strong>Nota:</strong> No momento da inscrição, o sistema exigirá que a pessoa informe o CPF do cônjuge, criando assim uma inscrição dupla atrelada aos dois cadastros.</span>
				</div>
			{/if}
		</div>

		<!-- Datas e Vagas -->
		<div class="af-section" style="display: {activeTab === 'schedule' ? 'block' : 'none'}">
			<div class="af-section-title"><h3>Cronograma e Vagas</h3></div>
			<div class="af-grid-3">
				<div class="af-field">
					<label for="start_date">Data de Início *</label>
					<input type="datetime-local" id="start_date" bind:value={formData.start_date} required />
				</div>
				<div class="af-field">
					<label for="duration_days">Duração (em dias) *</label>
					<input type="number" id="duration_days" bind:value={formData.duration_days} min="1" required />
				</div>
				<div class="af-field">
					<label for="total_vacancies">Total de Vagas *</label>
					<input type="number" id="total_vacancies" bind:value={formData.total_vacancies} min="1" required />
				</div>
			</div>

			<div class="af-section-title af-mt"><h3>Inscrição</h3></div>
			<div class="af-grid-2">
				<div class="af-field">
					<label for="registration_end_date">Fim das inscrições</label>
					<input type="datetime-local" id="registration_end_date" bind:value={formData.registration_end_date} />
					<small class="af-hint">Até quando dá para se inscrever e enviar o formulário. Sem data, vale até o início do evento.</small>
				</div>
				<div class="af-field">
					<label for="event_form_id">Formulário de inscrição</label>
					<select id="event_form_id" bind:value={formData.event_form_id} disabled={carregandoFormularios}>
						<option value="">Nenhum (a inscrição conclui na hora)</option>
						{#each formularios as f (f.id)}
							<option value={String(f.id)}>{f.nome}</option>
						{/each}
					</select>
					<small class="af-hint">
						Com formulário, a inscrição só conclui quando a pessoa envia, e a vaga só fica garantida no envio.
						{#if pode('formularios')}
							<a href="/admin/formularios" target="_blank" rel="noopener">+ Novo formulário</a> ·
							<button type="button" class="af-link" onclick={carregarFormularios}>atualizar a lista</button>
						{/if}
					</small>
				</div>
			</div>
			{#if editando && formData.event_form_id && formData.event_form_id !== formularioOriginal}
				<div class="af-alert af-alert--info af-mt">
					<span>
						<strong>Atenção:</strong> quem já se inscreveu também vai precisar enviar este formulário para concluir a inscrição.
					</span>
				</div>
			{/if}
		</div>

		<!-- Ingressos -->
		<div class="af-section" style="display: {activeTab === 'finance' ? 'block' : 'none'}">
			<div class="af-section-title">
				<h3>Ingressos</h3>
				<label class="af-toggle">
					<input type="checkbox" bind:checked={formData.is_paid_festival} />
					Evento Pago (Necessita de Ingressos)
				</label>
			</div>

			<div class="af-grid-3" style={formData.is_paid_festival ? '' : 'opacity: 0.5; pointer-events: none;'}>
				<div class="af-field">
					<label for="ticket_price">Valor do Ingresso (R$) *</label>
					<input type="number" step="0.01" id="ticket_price" bind:value={formData.ticket_price} disabled={!formData.is_paid_festival} required={formData.is_paid_festival} />
				</div>
				<div class="af-field">
					<label for="sale_start_date">Início das Vendas *</label>
					<input type="datetime-local" id="sale_start_date" bind:value={formData.sale_start_date} disabled={!formData.is_paid_festival} required={formData.is_paid_festival} />
				</div>
				<div class="af-field">
					<label for="payment_link">Link para Pagamento</label>
					<input type="url" id="payment_link" bind:value={formData.payment_link} disabled={!formData.is_paid_festival} placeholder="https://mercadopago.com/..." />
				</div>
			</div>
		</div>

		<div class="af-actions">
			{#if activeTab !== 'basic'}
				<button type="button" class="af-btn af-btn--secondary" onclick={prevTab}>← Voltar Etapa</button>
			{:else}
				<span class="af-actions-spacer"></span>
			{/if}

			{#if activeTab !== 'finance'}
				<button type="button" class="af-btn af-btn--primary" onclick={nextTab}>Próxima Etapa →</button>
			{:else}
				<button type="submit" class="af-btn af-btn--primary" disabled={loading}>
					{loading ? 'Salvando...' : editando ? 'Salvar alterações' : 'Finalizar e Criar Evento'}
				</button>
			{/if}
		</div>
	</form>
</div>

<style>
	.af-link {
		padding: 0;
		font: inherit;
		color: inherit;
		background: none;
		border: none;
		text-decoration: underline;
		cursor: pointer;
	}
</style>
