<script lang="ts">
	import { onMount } from 'svelte';
	import { apiFetch } from '$lib/api';
	import '$lib/styles/admin-form.css';

	let loading = $state(false);
	let error = $state('');
	let success = $state(false);
	let activeTab = $state('basic');

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
		payment_link: ''
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

		try {
			const res = await apiFetch('/activities', {
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

			const data = await res.json();
			success = true;
			window.scrollTo(0, 0);
			// Limpa o form se quiser
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
			<h2>Criar Novo Evento</h2>
			<p>Preencha os dados abaixo para publicar um evento simples (com ou sem ingressos).</p>
		</div>
		<a href="/dashboard" class="af-back-link">← Voltar</a>
	</div>

	{#if success}
		<div class="af-alert af-alert--success">
			<span><strong>Sucesso!</strong> O evento foi criado e já está salvo no sistema.</span>
			<button onclick={() => success = false} class="af-alert-close">Nova criação</button>
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

	<form class="af-form" onsubmit={handleSubmit}>

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
				<label for="image">Imagem de Capa *</label>
				<input type="file" id="image" accept="image/*" onchange={(e) => formData.image = e.currentTarget.files?.[0] || null} required />
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
					{loading ? 'Salvando...' : 'Finalizar e Criar Evento'}
				</button>
			{/if}
		</div>
	</form>
</div>
