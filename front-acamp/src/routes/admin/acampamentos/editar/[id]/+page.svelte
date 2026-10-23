<script lang="ts">
	import { onMount } from "svelte";
	import { page } from "$app/stores";
	import { apiFetch } from "$lib/api";

	let categories: any[] = $state([]);
	let loading = $state(false);
	let error = $state("");
	let success = $state(false);
	let activityId = $state("");

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
		image: "", // Edital image
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
		// Prazo para o selecionado responder o formulário; vencido, a vaga vai
		// automaticamente para o primeiro suplente da fila.
		camper_form_deadline: "",
		servant_form_deadline: "",

		// Raffle Dates Servants
		raffle_servant_subscription_start_date: "",
		raffle_servant_subscription_end_date: "",
		raffle_servant_date: "",

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

		activityId = $page.params.id || "";
		const resAct = await apiFetch(`/activities/${activityId}`);
		if (resAct.ok) {
			const dataAct = await resAct.json();
			const act = dataAct.activity;
			const camp = act.camping;

			// Format dates to datetime-local format (YYYY-MM-DDThh:mm)
			const fd = (d: string | number | Date | null | undefined) => {
				if (!d) return "";
				const dt = new Date(d);
				// adjust timezone manually or just string slice if it's ISO
				dt.setMinutes(dt.getMinutes() - dt.getTimezoneOffset());
				return dt.toISOString().slice(0, 16);
			};

			formData = {
				name: act.name,
				category_id: act.category_id,
				place: act.place,
				cover_image: null,
				year: act.year,
				start_date: fd(act.start_date),
				duration_days: act.duration_days,
				total_vacancies: act.total_vacancies,

				notice: camp.notice,
				term: camp.term,
				image: camp.image,
				minimal_age: camp.minimal_age,
				maximal_age: camp.maximal_age,
				camper_fee: camp.camper_fee,
				servant_fee: camp.servant_fee,

				planned_man_vacancies: camp.planned_man_vacancies,
				planned_woman_vacancies: camp.planned_woman_vacancies,
				planned_couple_vacancies: camp.planned_couple_vacancies,

				raffle_man_vacancies: camp.raffle_man_vacancies,
				raffle_woman_vacancies: camp.raffle_woman_vacancies,
				raffle_couple_vacancies: camp.raffle_couple_vacancies,
				raffle_total_vacancies: camp.raffle_total_vacancies,

				raffle_camper_subscription_start_date: fd(camp.raffle_camper_subscription_start_date),
				raffle_camper_subscription_end_date: fd(camp.raffle_camper_subscription_end_date),
				raffle_camper_date: fd(camp.raffle_camper_date),
				camper_form_deadline: fd(camp.camper_form_deadline),
				servant_form_deadline: fd(camp.servant_form_deadline),

				raffle_servant_subscription_start_date: fd(camp.raffle_servant_subscription_start_date),
				raffle_servant_subscription_end_date: fd(camp.raffle_servant_subscription_end_date),
				raffle_servant_date: fd(camp.raffle_servant_date),

				camper_registration_start_date: fd(camp.camper_registration_start_date),
				camper_registration_end_date: fd(camp.camper_registration_end_date),
				camper_payment_link: camp.camper_payment_link,
				camper_payment_date: fd(camp.camper_payment_date),

				servant_registration_start_date: fd(camp.servant_registration_start_date),
				servant_registration_end_date: fd(camp.servant_registration_end_date),
				servant_payment_link: camp.servant_payment_link,
				servant_payment_date: fd(camp.servant_payment_date),
			};
		}
	});

	function validateDates() {
		// Validar fluxo temporal do Campista
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

		// Copiar datas para Servos se solicitado
		if (copy_camper_dates) {
			formData.raffle_servant_subscription_start_date =
				formData.raffle_camper_subscription_start_date;
			formData.raffle_servant_subscription_end_date =
				formData.raffle_camper_subscription_end_date;
			formData.raffle_servant_date = formData.raffle_camper_date;
			formData.servant_form_deadline = formData.camper_form_deadline;
			formData.servant_registration_start_date =
				formData.camper_registration_start_date;
			formData.servant_registration_end_date =
				formData.camper_registration_end_date;
			formData.servant_payment_date = formData.camper_payment_date;
			formData.servant_payment_link = formData.camper_payment_link;
		}

		const body = new FormData();
		body.append("type", "Camping");

		Object.entries(formData).forEach(([key, value]) => {
			if (key === "cover_image" && value !== null && typeof value !== 'string') {
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
				}
				if (key === 'camper_registration_end_date') {
					body.append(`activity[participant_registration_end_date]`, value.toString());
				}

				if (activityFields.includes(key) || outKey === 'participant_fee' || key === 'servant_fee' || key === 'servant_registration_start_date' || key === 'servant_registration_end_date') {
					body.append(`activity[${outKey}]`, value.toString());
				} else {
					body.append(`camping[${key}]`, value.toString());
				}
			}
		});

		try {
			body.append("_method", "PUT");
			const res = await apiFetch(`/activities/${activityId}`, {
				method: "POST",
				body: body,
			});
			const data = await res.json();

			if (res.ok) {
				alert("Acampamento atualizado com sucesso!");
				window.location.href = "/admin/atividades";
			} else {
				console.error("Erro do backend:", data);
				if (data.errors) {
					// Dicionário de tradução amigável
					const fieldLabels: Record<string, string> = {
						name: "Nome do Acampamento", category_id: "Categoria", place: "Local", year: "Ano", cover_image: "Imagem da Capa",
						notice: "Edital", term: "Termo de Responsabilidade", start_date: "Data de Início", duration_days: "Duração",
						camper_fee: "Taxa Campista", servant_fee: "Taxa Servo", minimal_age: "Idade Mínima", maximal_age: "Idade Máxima",
						planned_man_vacancies: "Planejadas (Homens)", planned_woman_vacancies: "Planejadas (Mulheres)", planned_couple_vacancies: "Planejadas (Casais)",
						raffle_man_vacancies: "Sorteio (Homens)", raffle_woman_vacancies: "Sorteio (Mulheres)", raffle_couple_vacancies: "Sorteio (Casais)",
						raffle_camper_subscription_start_date: "Início Inscrição (Campista)", raffle_camper_subscription_end_date: "Fim Inscrição (Campista)", raffle_camper_date: "Data Sorteio (Campista)",
						camper_registration_start_date: "Início Matrícula (Campista)", camper_registration_end_date: "Fim Matrícula (Campista)", camper_payment_date: "Prazo Pgto (Campista)", camper_payment_link: "Link Pgto (Campista)",
						camper_form_deadline: "Prazo do Formulário (Campista)", servant_form_deadline: "Prazo do Formulário (Servo)",
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
						
						// Traduz mensagens genéricas do CakePHP
						if (rawMsg === "This field is required" || rawMsg === "This field cannot be left empty") {
							rawMsg = "Este campo é obrigatório.";
						} else if (rawMsg === "The provided value is invalid") {
							rawMsg = "O valor fornecido é inválido.";
						}

						fieldErrors[field] = rawMsg;
					}
				} else {
					error = data.error || data.message || "Erro interno do servidor (500). Verifique o console.";
				}
				window.scrollTo(0, 0);
			}
		} catch (err) {
			error = "Falha na comunicação com o servidor";
			window.scrollTo(0, 0);
		} finally {
			loading = false;
		}
	}
</script>

<div class="page-header">
	<div>
		<h2>Editar Acampamento</h2>
		<p class="text-muted">
			Atualize os dados do acampamento abaixo.
		</p>
	</div>
	<a href="/dashboard" class="back-link">← Voltar</a>
</div>



{#if error}
	<div class="alert error-alert">
		<strong>Atenção:</strong>
		{error}
	</div>
{/if}

<form class="camping-form" onsubmit={handleSubmit}>
	<!-- Informações Gerais -->
	<div class="form-section">
		<h3>1. Informações Básicas</h3>

		<div class="grid-2">
			<div class="form-group">
				<label for="name">Nome do Acampamento *</label>
				<input
					type="text"
					id="name"
					bind:value={formData.name}
					required
					placeholder="Ex: Acampamento FAC 2026"
					class:input-error={fieldErrors.name}
				/>
				{#if fieldErrors.name}<span class="error-text"
						>{fieldErrors.name}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="category_id">Categoria *</label>
				<select
					id="category_id"
					bind:value={formData.category_id}
					required
					class:input-error={fieldErrors.category_id}
				>
					<option value="">Selecione...</option>
					{#each categories as cat}
						<option value={cat.id}>{cat.name}</option>
					{/each}
				</select>
				{#if fieldErrors.category_id}<span class="error-text"
						>{fieldErrors.category_id}</span
					>{/if}
			</div>

			<div class="form-group">
				<label for="place">Local *</label>
				<input
					type="text"
					id="place"
					bind:value={formData.place}
					required
					class:input-error={fieldErrors.place}
				/>
				{#if fieldErrors.place}<span class="error-text"
						>{fieldErrors.place}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="year">Ano de Referência *</label>
				<input
					type="number"
					id="year"
					bind:value={formData.year}
					required
					class:input-error={fieldErrors.year}
				/>
				{#if fieldErrors.year}<span class="error-text"
						>{fieldErrors.year}</span
					>{/if}
			</div>

			<div class="form-group">
				<label for="cover_image">Imagem da Capa Principal</label>
				<input
					type="file"
					id="cover_image"
					accept="image/*"
					onchange={(e) =>
						(formData.cover_image =
							e.currentTarget.files?.[0] || null)}
					class:input-error={fieldErrors.cover_image}
				/>
				{#if fieldErrors.cover_image}<span class="error-text"
						>{fieldErrors.cover_image}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="notice">Título/Link do Edital</label>
				<input
					type="text"
					id="notice"
					bind:value={formData.notice}
					required
					class:input-error={fieldErrors.notice}
				/>
				{#if fieldErrors.notice}<span class="error-text"
						>{fieldErrors.notice}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="term">Link do Termo de Responsabilidade *</label>
				<input
					type="text"
					id="term"
					bind:value={formData.term}
					required
					class:input-error={fieldErrors.term}
				/>
				{#if fieldErrors.term}<span class="error-text"
						>{fieldErrors.term}</span
					>{/if}
			</div>
		</div>

		<div class="grid-3 mt-1">
			<div class="form-group">
				<label for="start_date">Data de Início</label>
				<input
					type="datetime-local"
					id="start_date"
					bind:value={formData.start_date}
					required
					class:input-error={fieldErrors.start_date}
				/>
				{#if fieldErrors.start_date}<span class="error-text"
						>{fieldErrors.start_date}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="duration_days">Duração (dias)</label>
				<input
					type="number"
					id="duration_days"
					bind:value={formData.duration_days}
					min="1"
					required
					class:input-error={fieldErrors.duration_days}
				/>
				{#if fieldErrors.duration_days}<span class="error-text"
						>{fieldErrors.duration_days}</span
					>{/if}
			</div>
			
		</div>
	</div>

	<!-- Valores e Idades -->
	<div class="form-section">
		<h3>2. Regras e Valores</h3>

		<div class="grid-2">
			<div class="form-group">
				<label for="camper_fee">Taxa Campista (R$)</label>
				<input
					type="number"
					step="0.01"
					id="camper_fee"
					bind:value={formData.camper_fee}
					required
					class:input-error={fieldErrors.camper_fee}
				/>
				{#if fieldErrors.camper_fee}<span class="error-text"
						>{fieldErrors.camper_fee}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="servant_fee">Taxa Servo (R$)</label>
				<input
					type="number"
					step="0.01"
					id="servant_fee"
					bind:value={formData.servant_fee}
					required
					class:input-error={fieldErrors.servant_fee}
				/>
				{#if fieldErrors.servant_fee}<span class="error-text"
						>{fieldErrors.servant_fee}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="minimal_age">Idade Mínima (Campista)</label>
				<input
					type="number"
					id="minimal_age"
					bind:value={formData.minimal_age}
					required
					class:input-error={fieldErrors.minimal_age}
				/>
				{#if fieldErrors.minimal_age}<span class="error-text"
						>{fieldErrors.minimal_age}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="maximal_age">Idade Máxima (Campista)</label>
				<input
					type="number"
					id="maximal_age"
					bind:value={formData.maximal_age}
					required
					class:input-error={fieldErrors.maximal_age}
				/>
				{#if fieldErrors.maximal_age}<span class="error-text"
						>{fieldErrors.maximal_age}</span
					>{/if}
			</div>
		</div>
	</div>

	<!-- Vagas Detalhadas -->
	<div class="form-section">
		<h3>3. Distribuição de Vagas</h3>

		<h4 class="sub-title">Vagas Planejadas (Total Interno)</h4>
		<div class="grid-3">
			<div class="form-group">
				<label for="planned_man_vacancies">Homens</label>
				<input
					type="number"
					id="planned_man_vacancies"
					bind:value={formData.planned_man_vacancies}
					required
					class:input-error={fieldErrors.planned_man_vacancies}
				/>
				{#if fieldErrors.planned_man_vacancies}<span class="error-text"
						>{fieldErrors.planned_man_vacancies}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="planned_woman_vacancies">Mulheres</label>
				<input
					type="number"
					id="planned_woman_vacancies"
					bind:value={formData.planned_woman_vacancies}
					required
					class:input-error={fieldErrors.planned_woman_vacancies}
				/>
				{#if fieldErrors.planned_woman_vacancies}<span
						class="error-text"
						>{fieldErrors.planned_woman_vacancies}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="planned_couple_vacancies">Casais</label>
				<input
					type="number"
					id="planned_couple_vacancies"
					bind:value={formData.planned_couple_vacancies}
					required
					class:input-error={fieldErrors.planned_couple_vacancies}
				/>
				{#if fieldErrors.planned_couple_vacancies}<span
						class="error-text"
						>{fieldErrors.planned_couple_vacancies}</span
					>{/if}
			</div>
		</div>

		<h4 class="sub-title mt-1">Vagas para Sorteio</h4>
		<div class="grid-4">
			<div class="form-group">
				<label for="raffle_man_vacancies">Homens</label>
				<input
					type="number"
					id="raffle_man_vacancies"
					bind:value={formData.raffle_man_vacancies}
					required
					class:input-error={fieldErrors.raffle_man_vacancies}
				/>
				{#if fieldErrors.raffle_man_vacancies}<span class="error-text"
						>{fieldErrors.raffle_man_vacancies}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="raffle_woman_vacancies">Mulheres</label>
				<input
					type="number"
					id="raffle_woman_vacancies"
					bind:value={formData.raffle_woman_vacancies}
					required
					class:input-error={fieldErrors.raffle_woman_vacancies}
				/>
				{#if fieldErrors.raffle_woman_vacancies}<span class="error-text"
						>{fieldErrors.raffle_woman_vacancies}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="raffle_couple_vacancies">Casais</label>
				<input
					type="number"
					id="raffle_couple_vacancies"
					bind:value={formData.raffle_couple_vacancies}
					required
					class:input-error={fieldErrors.raffle_couple_vacancies}
				/>
				{#if fieldErrors.raffle_couple_vacancies}<span
						class="error-text"
						>{fieldErrors.raffle_couple_vacancies}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="raffle_total_vacancies"
					>Total Sorteio (Calculado)</label
				>
				<input
					type="number"
					id="raffle_total_vacancies"
					value={raffleTotal}
					readonly
					class="readonly-input"
					class:input-error={fieldErrors.raffle_total_vacancies}
				/>
				{#if fieldErrors.raffle_total_vacancies}<span class="error-text"
						>{fieldErrors.raffle_total_vacancies}</span
					>{/if}
			</div>
		</div>
	</div>

	<!-- Cronograma Campistas -->
	<div class="form-section highlight-box">
		<h3>4. Cronograma: CAMPISTAS</h3>

		<div class="grid-3">
			<div class="form-group">
				<label for="raffle_camper_subscription_start_date"
					>Início da Inscrição (Sorteio)</label
				>
				<input
					type="datetime-local"
					id="raffle_camper_subscription_start_date"
					bind:value={formData.raffle_camper_subscription_start_date}
					required
					class:input-error={fieldErrors.raffle_camper_subscription_start_date}
				/>
				{#if fieldErrors.raffle_camper_subscription_start_date}<span
						class="error-text"
						>{fieldErrors.raffle_camper_subscription_start_date}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="raffle_camper_subscription_end_date"
					>Fim da Inscrição (Sorteio)</label
				>
				<input
					type="datetime-local"
					id="raffle_camper_subscription_end_date"
					bind:value={formData.raffle_camper_subscription_end_date}
					required
					class:input-error={fieldErrors.raffle_camper_subscription_end_date}
				/>
				{#if fieldErrors.raffle_camper_subscription_end_date}<span
						class="error-text"
						>{fieldErrors.raffle_camper_subscription_end_date}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="raffle_camper_date">Data do Sorteio Oficial</label>
				<input
					type="datetime-local"
					id="raffle_camper_date"
					bind:value={formData.raffle_camper_date}
					required
					class:input-error={fieldErrors.raffle_camper_date}
				/>
				{#if fieldErrors.raffle_camper_date}<span class="error-text"
						>{fieldErrors.raffle_camper_date}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="camper_form_deadline">Prazo do Formulário (Campista)</label>
				<input
					type="datetime-local"
					id="camper_form_deadline"
					bind:value={formData.camper_form_deadline}
					class:input-error={fieldErrors.camper_form_deadline}
				/>
				<small>
					Sorteado ou indicado que não enviar o formulário até essa data perde a
					vaga, e o primeiro suplente é chamado automaticamente. Em branco, a regra
					não se aplica.
				</small>
				{#if fieldErrors.camper_form_deadline}<span class="error-text"
						>{fieldErrors.camper_form_deadline}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="servant_form_deadline">Prazo do Formulário (Servo)</label>
				<input
					type="datetime-local"
					id="servant_form_deadline"
					bind:value={formData.servant_form_deadline}
					class:input-error={fieldErrors.servant_form_deadline}
				/>
				<small>
					Mesma regra para os servos: passado o prazo, a vaga vai para o suplente do
					mesmo setor.
				</small>
				{#if fieldErrors.servant_form_deadline}<span class="error-text"
						>{fieldErrors.servant_form_deadline}</span
					>{/if}
			</div>
		</div>

		<hr />

		<div class="grid-2 mt-1">
			<div class="form-group">
				<label for="camper_registration_start_date"
					>Início da Matrícula (Pós-Sorteio)</label
				>
				<input
					type="datetime-local"
					id="camper_registration_start_date"
					bind:value={formData.camper_registration_start_date}
					required
					class:input-error={fieldErrors.camper_registration_start_date}
				/>
				{#if fieldErrors.camper_registration_start_date}<span
						class="error-text"
						>{fieldErrors.camper_registration_start_date}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="camper_registration_end_date"
					>Fim da Matrícula</label
				>
				<input
					type="datetime-local"
					id="camper_registration_end_date"
					bind:value={formData.camper_registration_end_date}
					required
					class:input-error={fieldErrors.camper_registration_end_date}
				/>
				{#if fieldErrors.camper_registration_end_date}<span
						class="error-text"
						>{fieldErrors.camper_registration_end_date}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="camper_payment_date">Prazo Final do Pagamento</label
				>
				<input
					type="datetime-local"
					id="camper_payment_date"
					bind:value={formData.camper_payment_date}
					required
					class:input-error={fieldErrors.camper_payment_date}
				/>
				{#if fieldErrors.camper_payment_date}<span class="error-text"
						>{fieldErrors.camper_payment_date}</span
					>{/if}
			</div>
			<div class="form-group">
				<label for="camper_payment_link"
					>Link de Pagamento (Campista)</label
				>
				<input
					type="url"
					id="camper_payment_link"
					bind:value={formData.camper_payment_link}
					class:input-error={fieldErrors.camper_payment_link}
				/>
				{#if fieldErrors.camper_payment_link}<span class="error-text"
						>{fieldErrors.camper_payment_link}</span
					>{/if}
			</div>
		</div>
	</div>

	<!-- Cronograma Servos -->
	<div class="form-section highlight-box">
		<div
			style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f3f4f6; padding-bottom: 0.5rem; margin-bottom: 1.5rem;"
		>
			<h3 style="margin: 0; border: none; padding: 0;">
				5. Cronograma: SERVOS
			</h3>
			<label
				style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; cursor: pointer; color: #374151;"
			>
				<input
					type="checkbox"
					bind:checked={copy_camper_dates}
					style="width: 1.2rem; height: 1.2rem; accent-color: #10b981;"
				/>
				Usar mesmos dados dos Campistas
			</label>
		</div>

		{#if !copy_camper_dates}
			<div class="grid-3">
				<div class="form-group">
					<label for="raffle_servant_subscription_start_date"
						>Início da Inscrição</label
					>
					<input
						type="datetime-local"
						id="raffle_servant_subscription_start_date"
						bind:value={
							formData.raffle_servant_subscription_start_date
						}
						required
						class:input-error={fieldErrors.raffle_servant_subscription_start_date}
					/>
					{#if fieldErrors.raffle_servant_subscription_start_date}<span
							class="error-text"
							>{fieldErrors.raffle_servant_subscription_start_date}</span
						>{/if}
				</div>
				<div class="form-group">
					<label for="raffle_servant_subscription_end_date"
						>Fim da Inscrição</label
					>
					<input
						type="datetime-local"
						id="raffle_servant_subscription_end_date"
						bind:value={
							formData.raffle_servant_subscription_end_date
						}
						required
						class:input-error={fieldErrors.raffle_servant_subscription_end_date}
					/>
					{#if fieldErrors.raffle_servant_subscription_end_date}<span
							class="error-text"
							>{fieldErrors.raffle_servant_subscription_end_date}</span
						>{/if}
				</div>
				<div class="form-group">
					<label for="raffle_servant_date"
						>Data do Sorteio (Se houver)</label
					>
					<input
						type="datetime-local"
						id="raffle_servant_date"
						bind:value={formData.raffle_servant_date}
						required
						class:input-error={fieldErrors.raffle_servant_date}
					/>
					{#if fieldErrors.raffle_servant_date}<span
							class="error-text"
							>{fieldErrors.raffle_servant_date}</span
						>{/if}
				</div>
			</div>

			<hr />

			<div class="grid-2 mt-1">
				<div class="form-group">
					<label for="servant_registration_start_date"
						>Início da Confirmação</label
					>
					<input
						type="datetime-local"
						id="servant_registration_start_date"
						bind:value={formData.servant_registration_start_date}
						required
						class:input-error={fieldErrors.servant_registration_start_date}
					/>
					{#if fieldErrors.servant_registration_start_date}<span
							class="error-text"
							>{fieldErrors.servant_registration_start_date}</span
						>{/if}
				</div>
				<div class="form-group">
					<label for="servant_registration_end_date"
						>Fim da Confirmação</label
					>
					<input
						type="datetime-local"
						id="servant_registration_end_date"
						bind:value={formData.servant_registration_end_date}
						required
						class:input-error={fieldErrors.servant_registration_end_date}
					/>
					{#if fieldErrors.servant_registration_end_date}<span
							class="error-text"
							>{fieldErrors.servant_registration_end_date}</span
						>{/if}
				</div>
				<div class="form-group">
					<label for="servant_payment_date"
						>Prazo Final do Pagamento</label
					>
					<input
						type="datetime-local"
						id="servant_payment_date"
						bind:value={formData.servant_payment_date}
						required
						class:input-error={fieldErrors.servant_payment_date}
					/>
					{#if fieldErrors.servant_payment_date}<span
							class="error-text"
							>{fieldErrors.servant_payment_date}</span
						>{/if}
				</div>
				<div class="form-group">
					<label for="servant_payment_link"
						>Link de Pagamento (Servo)</label
					>
					<input
						type="url"
						id="servant_payment_link"
						bind:value={formData.servant_payment_link}
						class:input-error={fieldErrors.servant_payment_link}
					/>
					{#if fieldErrors.servant_payment_link}<span
							class="error-text"
							>{fieldErrors.servant_payment_link}</span
						>{/if}
				</div>
			</div>
		{/if}
	</div>

	<div class="form-actions">
		<button type="submit" class="submit-btn" disabled={loading}>
				{loading ? "Salvando Alterações..." : "Salvar Alterações"}
		</button>
	</div>
</form>

<style>
	.page-header {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
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
	.back-link {
		color: #4b5563;
		text-decoration: none;
		font-weight: 600;
		font-size: 0.9rem;
	}
	.back-link:hover {
		color: #111827;
	}

	.alert {
		padding: 1rem 1.5rem;
		border-radius: 8px;
		margin-bottom: 1.5rem;
		display: flex;
		justify-content: space-between;
	}
	.success-alert {
		background: #d1fae5;
		color: #065f46;
		border: 1px solid #10b981;
	}
	.error-alert {
		background: #fee2e2;
		color: #991b1b;
		border: 1px solid #ef4444;
	}
	.close-btn {
		background: none;
		border: 1px solid currentColor;
		cursor: pointer;
		font-weight: bold;
		border-radius: 4px;
	}

	.camping-form {
		display: flex;
		flex-direction: column;
		gap: 1.5rem;
	}
	.form-section {
		background: #fff;
		padding: 2rem;
		border-radius: 12px;
		border: 1px solid #e5e7eb;
		box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
	}
	.form-section h3 {
		margin: 0 0 1.5rem 0;
		font-size: 1.2rem;
		color: #111827;
		border-bottom: 2px solid #f3f4f6;
		padding-bottom: 0.5rem;
	}
	.sub-title {
		font-size: 0.95rem;
		color: #6b7280;
		margin: 0 0 1rem 0;
		text-transform: uppercase;
		letter-spacing: 0.05em;
	}

	.highlight-box {
		background: #f9fafb;
		border: 1px solid #d1d5db;
	}
	hr {
		border: 0;
		border-top: 1px solid #e5e7eb;
		margin: 1.5rem 0;
	}

	.grid-2 {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 1.5rem;
	}
	.grid-3 {
		display: grid;
		grid-template-columns: 1fr 1fr 1fr;
		gap: 1.5rem;
	}
	.grid-4 {
		display: grid;
		grid-template-columns: repeat(4, 1fr);
		gap: 1.5rem;
	}
	.mt-1 {
		margin-top: 1rem;
	}

	.form-group {
		display: flex;
		flex-direction: column;
		gap: 0.5rem;
	}
	.form-group label {
		font-size: 0.85rem;
		font-weight: 600;
		color: #4b5563;
	}
	.form-group input,
	.form-group select {
		padding: 0.75rem;
		border: 1px solid #d1d5db;
		border-radius: 6px;
		font-size: 0.95rem;
		font-family: inherit;
		transition: border-color 0.2s;
	}
	.form-group input:focus,
	.form-group select:focus {
		outline: none;
		border-color: #6fdec2;
		box-shadow: 0 0 0 3px rgba(111, 222, 194, 0.2);
	}

	.readonly-input {
		background: #f3f4f6 !important;
		cursor: not-allowed;
		color: #6b7280;
		font-weight: bold;
	}
	.input-error {
		border-color: #ef4444 !important;
		box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
	}
	.error-text {
		color: #ef4444;
		font-size: 0.8rem;
		margin-top: 0.25rem;
		font-weight: 500;
	}

	.form-actions {
		display: flex;
		justify-content: flex-end;
		margin-top: 1rem;
		padding-bottom: 2rem;
	}
	.submit-btn {
		background: #111827;
		color: #6fdec2;
		border: none;
		padding: 1rem 3rem;
		border-radius: 8px;
		font-weight: 700;
		font-size: 1.1rem;
		cursor: pointer;
		transition: all 0.2s;
	}
	.submit-btn:hover:not(:disabled) {
		background: #1f2937;
		color: #fff;
		transform: translateY(-2px);
	}
	.submit-btn:disabled {
		opacity: 0.6;
		cursor: not-allowed;
	}
</style>
