<script lang="ts">
	import { onMount } from "svelte";
	import { apiFetch } from "$lib/api";
	import "$lib/styles/admin-form.css";

	let categories: any[] = $state([]);
	let loading = $state(false);
	let error = $state("");
	let success = $state(false);
	let activeTab = $state('basic');

	const tabs = ['basic', 'rules', 'campers', 'servants'];
	const tabLabels: Record<string, string> = {
		basic: '📍 Básico',
		rules: '📝 Regras e Valores',
		campers: '🏕️ Campistas',
		servants: '🛠️ Servos',
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

	let copy_camper_dates = $state(true);
	let fieldErrors: Record<string, string> = $state({});

	let formData = $state({
		// Activity
		name: "",
		category_id: "",
		place: "",
		cover_image: null as File | null,
		year: new Date().getFullYear(),
		start_date: "",
		duration_days: 1,
		total_vacancies: 0,

		// Camping General
		notice: "",
		term: "",
		minimal_age: 0,
		maximal_age: 99,
		camper_fee: 0,
		servant_fee: 0,

		// Planned Vacancies
		planned_man_vacancies: 0,
		planned_woman_vacancies: 0,
		planned_couple_vacancies: 0,

		// Raffle Vacancies
		raffle_man_vacancies: 0,
		raffle_woman_vacancies: 0,
		raffle_couple_vacancies: 0,
		raffle_total_vacancies: 0,

		// Raffle Dates Campers
		raffle_camper_subscription_start_date: "",
		raffle_camper_subscription_end_date: "",
		raffle_camper_date: "",
		// Prazo para o sorteado/indicado responder o formulário. Vencido o prazo,
		// a vaga é repassada automaticamente ao primeiro suplente da fila.
		camper_form_deadline: "",

		// Raffle Dates Servants
		raffle_servant_subscription_start_date: "",
		raffle_servant_subscription_end_date: "",
		raffle_servant_date: "",
		servant_form_deadline: "",

		// Registration Campers
		camper_registration_start_date: "",
		camper_registration_end_date: "",
		camper_payment_link: "",
		camper_payment_date: "",

		// Registration Servants
		servant_registration_start_date: "",
		servant_registration_end_date: "",
		servant_payment_link: "",
		servant_payment_date: "",
	});

	let raffleTotal = $derived(
		(Number(formData.raffle_man_vacancies) || 0) +
			(Number(formData.raffle_woman_vacancies) || 0) +
			(Number(formData.raffle_couple_vacancies) || 0) * 2,
	);

	onMount(async () => {
		const res = await apiFetch("/categories");
		if (res.ok) {
			const data = await res.json();
			categories = (data.data || []).filter((c: any) => c.type === "Acampamento");
		}
	});

	function validateDates() {
		if (
			formData.raffle_camper_subscription_start_date &&
			formData.raffle_camper_subscription_end_date
		) {
			if (
				new Date(formData.raffle_camper_subscription_end_date) <=
				new Date(formData.raffle_camper_subscription_start_date)
			) {
				return "Fim da inscrição deve ser posterior ao início.";
			}
		}
		if (
			formData.raffle_camper_subscription_end_date &&
			formData.raffle_camper_date
		) {
			if (
				new Date(formData.raffle_camper_date) <
				new Date(formData.raffle_camper_subscription_end_date)
			) {
				return "O sorteio não pode ocorrer antes do fim das inscrições.";
			}
		}
		if (
			formData.raffle_camper_date &&
			formData.camper_registration_start_date
		) {
			if (
				new Date(formData.camper_registration_start_date) <
				new Date(formData.raffle_camper_date)
			) {
				return "A matrícula não pode começar antes do sorteio.";
			}
		}
		if (formData.raffle_camper_date && formData.camper_form_deadline) {
			if (
				new Date(formData.camper_form_deadline) <=
				new Date(formData.raffle_camper_date)
			) {
				return "O prazo do formulário precisa ser depois do sorteio dos campistas.";
			}
		}
		if (formData.raffle_servant_date && formData.servant_form_deadline) {
			if (
				new Date(formData.servant_form_deadline) <=
				new Date(formData.raffle_servant_date)
			) {
				return "O prazo do formulário dos servos precisa ser depois do sorteio dos servos.";
			}
		}
		if (formData.start_date && formData.camper_payment_date) {
			if (
				new Date(formData.start_date) <
				new Date(formData.camper_payment_date)
			) {
				return "O acampamento não pode iniciar antes do prazo final de pagamento.";
			}
		}
		return null;
	}

	async function handleSubmit(e: Event) {
		e.preventDefault();
		loading = true;
		error = "";
		success = false;
		fieldErrors = {};

		const dateError = validateDates();
		if (dateError) {
			error = dateError;
			loading = false;
			window.scrollTo(0, 0);
			return;
		}

		if (copy_camper_dates) {
			formData.raffle_servant_subscription_start_date = formData.raffle_camper_subscription_start_date;
			formData.raffle_servant_subscription_end_date = formData.raffle_camper_subscription_end_date;
			formData.raffle_servant_date = formData.raffle_camper_date;
			formData.servant_form_deadline = formData.camper_form_deadline;
			formData.servant_registration_start_date = formData.camper_registration_start_date;
			formData.servant_registration_end_date = formData.camper_registration_end_date;
			formData.servant_payment_date = formData.camper_payment_date;
			formData.servant_payment_link = formData.camper_payment_link;
		}

		const body = new FormData();
		body.append("type", "Camping");

		Object.entries(formData).forEach(([key, value]) => {
			if (key === "cover_image" && value !== null) {
				body.append("activity[image]", value as File);
			} else if (key === "total_vacancies") {
				let plannedTotal = (Number(formData.planned_man_vacancies) || 0) + (Number(formData.planned_woman_vacancies) || 0) + ((Number(formData.planned_couple_vacancies) || 0) * 2);
				body.append("activity[total_vacancies]", plannedTotal.toString());
			} else if (key === "raffle_total_vacancies") {
				body.append("camping[raffle_total_vacancies]", raffleTotal.toString());
			} else if (value !== null && value !== "") {
				const activityFields = ['name', 'category_id', 'place', 'year', 'start_date', 'duration_days'];
				let outKey = key;
				if (key === 'camper_fee') outKey = 'participant_fee';

				if (key === 'camper_registration_start_date') {
					body.append(`activity[participant_registration_start_date]`, value.toString());
				} else if (key === 'camper_registration_end_date') {
					body.append(`activity[participant_registration_end_date]`, value.toString());
				} else if (key === 'camper_payment_date') {
					body.append(`activity[participant_payment_date]`, value.toString());
				} else if (key === 'camper_payment_link') {
					body.append(`activity[participant_payment_link]`, value.toString());
				} else if (activityFields.includes(key) || outKey === 'participant_fee' || key === 'servant_fee' || key === 'servant_registration_start_date' || key === 'servant_registration_end_date' || key === 'servant_payment_date' || key === 'servant_payment_link') {
					body.append(`activity[${outKey}]`, value.toString());
				} else {
					body.append(`camping[${key}]`, value.toString());
				}
			}
		});

		try {
			const res = await apiFetch("/activities", {
				method: "POST",
				body: body,
			});
			const data = await res.json();

			if (res.ok) {
				success = true;
				window.scrollTo(0, 0);
			} else {
				if (data.errors) {
					const fieldLabels: Record<string, string> = {
						name: "Nome do Acampamento", category_id: "Categoria", place: "Local", year: "Ano", cover_image: "Imagem da Capa",
						notice: "Edital", term: "Termo de Responsabilidade", start_date: "Data de Início", duration_days: "Duração",
						camper_fee: "Taxa Campista", servant_fee: "Taxa Servo", minimal_age: "Idade Mínima", maximal_age: "Idade Máxima",
						planned_man_vacancies: "Planejadas (Homens)", planned_woman_vacancies: "Planejadas (Mulheres)", planned_couple_vacancies: "Planejadas (Casais)",
						raffle_man_vacancies: "Sorteio (Homens)", raffle_woman_vacancies: "Sorteio (Mulheres)", raffle_couple_vacancies: "Sorteio (Casais)",
						raffle_camper_subscription_start_date: "Início Inscrição (Campista)", raffle_camper_subscription_end_date: "Fim Inscrição (Campista)", raffle_camper_date: "Data Sorteio (Campista)",
						camper_form_deadline: "Prazo do Formulário (Campista)", servant_form_deadline: "Prazo do Formulário (Servo)",
						camper_registration_start_date: "Início Matrícula (Campista)", camper_registration_end_date: "Fim Matrícula (Campista)", camper_payment_date: "Prazo Pgto (Campista)", camper_payment_link: "Link Pgto (Campista)",
						raffle_servant_subscription_start_date: "Início Inscrição (Servo)", raffle_servant_subscription_end_date: "Fim Inscrição (Servo)", raffle_servant_date: "Data Sorteio (Servo)",
						servant_registration_start_date: "Início Confirmação (Servo)", servant_registration_end_date: "Fim Confirmação (Servo)", servant_payment_date: "Prazo Pgto (Servo)", servant_payment_link: "Link Pgto (Servo)"
					};

					const failedKeys = Object.keys(data.errors)
						.map(key => fieldLabels[key] || key)
						.join(", ");

					error = `Pendências nos campos: ${failedKeys}. Veja os detalhes em vermelho abaixo.`;

					for (const field in data.errors) {
						const rule = Object.keys(data.errors[field])[0];
						let rawMsg = data.errors[field][rule];
						if (rawMsg === "This field is required" || rawMsg === "This field cannot be left empty") rawMsg = "Este campo é obrigatório.";
						else if (rawMsg === "The provided value is invalid") rawMsg = "O valor fornecido é inválido.";
						fieldErrors[field] = rawMsg;
					}
				} else {
					error = data.error || data.message || "Erro interno do servidor (500). Verifique o console.";
				}
				window.scrollTo(0, 0);
			}
		} catch (err: any) {
			error = "Falha na comunicação com o servidor: " + err.message;
			window.scrollTo(0, 0);
		} finally {
			loading = false;
		}
	}
</script>

<div class="admin-form-page">
	<div class="af-header">
		<div>
			<h2>Criar Novo Acampamento</h2>
			<p>Preencha os dados abaixo. Eles estão separados por blocos para facilitar a organização.</p>
		</div>
		<a href="/dashboard" class="af-back-link">← Voltar</a>
	</div>

	{#if success}
		<div class="af-alert af-alert--success">
			<span><strong>Sucesso!</strong> O acampamento foi criado perfeitamente.</span>
			<button onclick={() => (success = false)} class="af-alert-close">Nova criação</button>
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

		<div class="af-section" style="display: {activeTab === 'basic' ? 'block' : 'none'};">
			<div class="af-section-title"><h3>Informações Básicas</h3></div>
			<div class="af-grid-2">
				<div class="af-field" class:af-field--error={fieldErrors.name}>
					<label for="name">Nome do Acampamento *</label>
					<input type="text" id="name" bind:value={formData.name} required placeholder="Ex: Acampamento FAC 2026" />
					{#if fieldErrors.name}<span class="af-error-text">{fieldErrors.name}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.category_id}>
					<label for="category_id">Categoria *</label>
					<select id="category_id" bind:value={formData.category_id} required>
						<option value="">Selecione...</option>
						{#each categories as cat}
							<option value={cat.id}>{cat.name}</option>
						{/each}
					</select>
					{#if fieldErrors.category_id}<span class="af-error-text">{fieldErrors.category_id}</span>{/if}
				</div>

				<div class="af-field" class:af-field--error={fieldErrors.place}>
					<label for="place">Local *</label>
					<input type="text" id="place" bind:value={formData.place} required />
					{#if fieldErrors.place}<span class="af-error-text">{fieldErrors.place}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.year}>
					<label for="year">Ano de Referência *</label>
					<input type="number" id="year" bind:value={formData.year} required />
					{#if fieldErrors.year}<span class="af-error-text">{fieldErrors.year}</span>{/if}
				</div>

				<div class="af-field" class:af-field--error={fieldErrors.cover_image}>
					<label for="cover_image">Imagem da Capa Principal</label>
					<input type="file" id="cover_image" accept="image/*" onchange={(e) => (formData.cover_image = e.currentTarget.files?.[0] || null)} />
					{#if fieldErrors.cover_image}<span class="af-error-text">{fieldErrors.cover_image}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.notice}>
					<label for="notice">Título/Link do Edital *</label>
					<input type="text" id="notice" bind:value={formData.notice} required />
					{#if fieldErrors.notice}<span class="af-error-text">{fieldErrors.notice}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.term}>
					<label for="term">Link do Termo de Responsabilidade *</label>
					<input type="text" id="term" bind:value={formData.term} required />
					{#if fieldErrors.term}<span class="af-error-text">{fieldErrors.term}</span>{/if}
				</div>
			</div>

			<div class="af-grid-3 af-mt">
				<div class="af-field" class:af-field--error={fieldErrors.start_date}>
					<label for="start_date">Data de Início *</label>
					<input type="datetime-local" id="start_date" bind:value={formData.start_date} required />
					{#if fieldErrors.start_date}<span class="af-error-text">{fieldErrors.start_date}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.duration_days}>
					<label for="duration_days">Duração (dias) *</label>
					<input type="number" id="duration_days" bind:value={formData.duration_days} min="1" required />
					{#if fieldErrors.duration_days}<span class="af-error-text">{fieldErrors.duration_days}</span>{/if}
				</div>
			</div>
		</div>

		<div class="af-section" style="display: {activeTab === 'rules' ? 'block' : 'none'};">
			<div class="af-section-title"><h3>Regras, Valores e Vagas</h3></div>
			<div class="af-grid-2">
				<div class="af-field" class:af-field--error={fieldErrors.camper_fee}>
					<label for="camper_fee">Taxa Campista (R$) *</label>
					<input type="number" step="0.01" id="camper_fee" bind:value={formData.camper_fee} required />
					{#if fieldErrors.camper_fee}<span class="af-error-text">{fieldErrors.camper_fee}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.servant_fee}>
					<label for="servant_fee">Taxa Servo (R$) *</label>
					<input type="number" step="0.01" id="servant_fee" bind:value={formData.servant_fee} required />
					{#if fieldErrors.servant_fee}<span class="af-error-text">{fieldErrors.servant_fee}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.minimal_age}>
					<label for="minimal_age">Idade Mínima (Campista) *</label>
					<input type="number" id="minimal_age" bind:value={formData.minimal_age} required />
					{#if fieldErrors.minimal_age}<span class="af-error-text">{fieldErrors.minimal_age}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.maximal_age}>
					<label for="maximal_age">Idade Máxima (Campista) *</label>
					<input type="number" id="maximal_age" bind:value={formData.maximal_age} required />
					{#if fieldErrors.maximal_age}<span class="af-error-text">{fieldErrors.maximal_age}</span>{/if}
				</div>
			</div>

			<hr class="af-divider" />
			<h4 class="af-sub-title">Vagas Planejadas (Total Interno)</h4>
			<div class="af-grid-3">
				<div class="af-field" class:af-field--error={fieldErrors.planned_man_vacancies}>
					<label for="planned_man_vacancies">Homens</label>
					<input type="number" id="planned_man_vacancies" bind:value={formData.planned_man_vacancies} required />
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.planned_woman_vacancies}>
					<label for="planned_woman_vacancies">Mulheres</label>
					<input type="number" id="planned_woman_vacancies" bind:value={formData.planned_woman_vacancies} required />
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.planned_couple_vacancies}>
					<label for="planned_couple_vacancies">Casais</label>
					<input type="number" id="planned_couple_vacancies" bind:value={formData.planned_couple_vacancies} required />
				</div>
			</div>

			<h4 class="af-sub-title af-mt">Vagas para Sorteio</h4>
			<div class="af-grid-4">
				<div class="af-field" class:af-field--error={fieldErrors.raffle_man_vacancies}>
					<label for="raffle_man_vacancies">Homens</label>
					<input type="number" id="raffle_man_vacancies" bind:value={formData.raffle_man_vacancies} required />
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.raffle_woman_vacancies}>
					<label for="raffle_woman_vacancies">Mulheres</label>
					<input type="number" id="raffle_woman_vacancies" bind:value={formData.raffle_woman_vacancies} required />
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.raffle_couple_vacancies}>
					<label for="raffle_couple_vacancies">Casais</label>
					<input type="number" id="raffle_couple_vacancies" bind:value={formData.raffle_couple_vacancies} required />
				</div>
				<div class="af-field">
					<label for="raffle_total_vacancies">Total Sorteio (Calculado)</label>
					<input type="number" id="raffle_total_vacancies" value={raffleTotal} readonly />
				</div>
			</div>
		</div>

		<div class="af-section af-section--highlight" style="display: {activeTab === 'campers' ? 'block' : 'none'};">
			<div class="af-section-title"><h3>Cronograma: CAMPISTAS</h3></div>
			<div class="af-grid-3">
				<div class="af-field" class:af-field--error={fieldErrors.raffle_camper_subscription_start_date}>
					<label for="raffle_camper_subscription_start_date">Início da Inscrição (Sorteio) *</label>
					<input type="datetime-local" id="raffle_camper_subscription_start_date" bind:value={formData.raffle_camper_subscription_start_date} required />
					{#if fieldErrors.raffle_camper_subscription_start_date}<span class="af-error-text">{fieldErrors.raffle_camper_subscription_start_date}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.raffle_camper_subscription_end_date}>
					<label for="raffle_camper_subscription_end_date">Fim da Inscrição (Sorteio) *</label>
					<input type="datetime-local" id="raffle_camper_subscription_end_date" bind:value={formData.raffle_camper_subscription_end_date} required />
					{#if fieldErrors.raffle_camper_subscription_end_date}<span class="af-error-text">{fieldErrors.raffle_camper_subscription_end_date}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.raffle_camper_date}>
					<label for="raffle_camper_date">Data do Sorteio Oficial *</label>
					<input type="datetime-local" id="raffle_camper_date" bind:value={formData.raffle_camper_date} required />
					{#if fieldErrors.raffle_camper_date}<span class="af-error-text">{fieldErrors.raffle_camper_date}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.camper_form_deadline}>
					<label for="camper_form_deadline">Prazo para responder o formulário</label>
					<input type="datetime-local" id="camper_form_deadline" bind:value={formData.camper_form_deadline} />
					<span class="af-hint">Quem for sorteado ou indicado e não enviar o formulário até essa data perde a vaga, e o primeiro suplente da fila é chamado automaticamente. Deixe em branco para não aplicar essa regra.</span>
					{#if fieldErrors.camper_form_deadline}<span class="af-error-text">{fieldErrors.camper_form_deadline}</span>{/if}
				</div>
			</div>
			<hr class="af-divider" />
			<div class="af-grid-2 af-mt">
				<div class="af-field" class:af-field--error={fieldErrors.camper_registration_start_date}>
					<label for="camper_registration_start_date">Início da Matrícula (Pós-Sorteio) *</label>
					<input type="datetime-local" id="camper_registration_start_date" bind:value={formData.camper_registration_start_date} required />
					{#if fieldErrors.camper_registration_start_date}<span class="af-error-text">{fieldErrors.camper_registration_start_date}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.camper_registration_end_date}>
					<label for="camper_registration_end_date">Fim da Matrícula *</label>
					<input type="datetime-local" id="camper_registration_end_date" bind:value={formData.camper_registration_end_date} required />
					{#if fieldErrors.camper_registration_end_date}<span class="af-error-text">{fieldErrors.camper_registration_end_date}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.camper_payment_date}>
					<label for="camper_payment_date">Prazo Final do Pagamento *</label>
					<input type="datetime-local" id="camper_payment_date" bind:value={formData.camper_payment_date} required />
					{#if fieldErrors.camper_payment_date}<span class="af-error-text">{fieldErrors.camper_payment_date}</span>{/if}
				</div>
				<div class="af-field" class:af-field--error={fieldErrors.camper_payment_link}>
					<label for="camper_payment_link">Link de Pagamento (Campista) *</label>
					<input type="url" id="camper_payment_link" bind:value={formData.camper_payment_link} required />
					{#if fieldErrors.camper_payment_link}<span class="af-error-text">{fieldErrors.camper_payment_link}</span>{/if}
				</div>
			</div>
		</div>

		<div class="af-section af-section--highlight" style="display: {activeTab === 'servants' ? 'block' : 'none'};">
			<div class="af-section-title">
				<h3>Cronograma: SERVOS</h3>
				<label class="af-toggle">
					<input type="checkbox" bind:checked={copy_camper_dates} />
					Usar mesmos dados dos Campistas
				</label>
			</div>

			{#if !copy_camper_dates}
				<div class="af-grid-3">
					<div class="af-field" class:af-field--error={fieldErrors.raffle_servant_subscription_start_date}>
						<label for="raffle_servant_subscription_start_date">Início da Inscrição *</label>
						<input type="datetime-local" id="raffle_servant_subscription_start_date" bind:value={formData.raffle_servant_subscription_start_date} required />
						{#if fieldErrors.raffle_servant_subscription_start_date}<span class="af-error-text">{fieldErrors.raffle_servant_subscription_start_date}</span>{/if}
					</div>
					<div class="af-field" class:af-field--error={fieldErrors.raffle_servant_subscription_end_date}>
						<label for="raffle_servant_subscription_end_date">Fim da Inscrição *</label>
						<input type="datetime-local" id="raffle_servant_subscription_end_date" bind:value={formData.raffle_servant_subscription_end_date} required />
						{#if fieldErrors.raffle_servant_subscription_end_date}<span class="af-error-text">{fieldErrors.raffle_servant_subscription_end_date}</span>{/if}
					</div>
					<div class="af-field" class:af-field--error={fieldErrors.raffle_servant_date}>
						<label for="raffle_servant_date">Data do Sorteio (Se houver) *</label>
						<input type="datetime-local" id="raffle_servant_date" bind:value={formData.raffle_servant_date} required />
						{#if fieldErrors.raffle_servant_date}<span class="af-error-text">{fieldErrors.raffle_servant_date}</span>{/if}
					</div>
					<div class="af-field" class:af-field--error={fieldErrors.servant_form_deadline}>
						<label for="servant_form_deadline">Prazo para responder o formulário</label>
						<input type="datetime-local" id="servant_form_deadline" bind:value={formData.servant_form_deadline} />
						<span class="af-hint">Mesma regra dos campistas: passado o prazo sem formulário enviado, a vaga vai para o suplente do mesmo setor.</span>
						{#if fieldErrors.servant_form_deadline}<span class="af-error-text">{fieldErrors.servant_form_deadline}</span>{/if}
					</div>
				</div>
				<hr class="af-divider" />
				<div class="af-grid-2 af-mt">
					<div class="af-field" class:af-field--error={fieldErrors.servant_registration_start_date}>
						<label for="servant_registration_start_date">Início da Confirmação *</label>
						<input type="datetime-local" id="servant_registration_start_date" bind:value={formData.servant_registration_start_date} required />
						{#if fieldErrors.servant_registration_start_date}<span class="af-error-text">{fieldErrors.servant_registration_start_date}</span>{/if}
					</div>
					<div class="af-field" class:af-field--error={fieldErrors.servant_registration_end_date}>
						<label for="servant_registration_end_date">Fim da Confirmação *</label>
						<input type="datetime-local" id="servant_registration_end_date" bind:value={formData.servant_registration_end_date} required />
						{#if fieldErrors.servant_registration_end_date}<span class="af-error-text">{fieldErrors.servant_registration_end_date}</span>{/if}
					</div>
					<div class="af-field" class:af-field--error={fieldErrors.servant_payment_date}>
						<label for="servant_payment_date">Prazo Final do Pagamento *</label>
						<input type="datetime-local" id="servant_payment_date" bind:value={formData.servant_payment_date} required />
						{#if fieldErrors.servant_payment_date}<span class="af-error-text">{fieldErrors.servant_payment_date}</span>{/if}
					</div>
					<div class="af-field" class:af-field--error={fieldErrors.servant_payment_link}>
						<label for="servant_payment_link">Link de Pagamento (Servo) *</label>
						<input type="url" id="servant_payment_link" bind:value={formData.servant_payment_link} required />
						{#if fieldErrors.servant_payment_link}<span class="af-error-text">{fieldErrors.servant_payment_link}</span>{/if}
					</div>
				</div>
			{/if}
		</div>

		<div class="af-actions">
			{#if activeTab !== 'basic'}
				<button type="button" class="af-btn af-btn--secondary" onclick={prevTab}>← Voltar Etapa</button>
			{:else}
				<span class="af-actions-spacer"></span>
			{/if}

			{#if activeTab !== 'servants'}
				<button type="button" class="af-btn af-btn--primary" onclick={nextTab}>Próxima Etapa →</button>
			{:else}
				<button type="submit" class="af-btn af-btn--primary" disabled={loading}>
					{loading ? "Salvando..." : "Finalizar e Criar Acampamento"}
				</button>
			{/if}
		</div>
	</form>
</div>
