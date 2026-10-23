<script lang="ts">
	import { page } from "$app/stores";
	import { apiFetch } from "$lib/api";
	import { auth } from "$lib/stores/auth.svelte";
	import { getMinhasInscricoes, type MinhaInscricao } from "$lib/services/inscricaoService";
	import { onMount } from "svelte";

	// Hierarquia por idade dos acampamentos (a mais nova primeiro). Casais e
	// Discipulados ficam fora da cadeia — cada um só desbloqueia servir nele mesmo.
	// Mantém a mesma regra do backend (InscricoesController::checkAcampamentoEligibility).
	const CATEGORY_CHAIN: Record<string, number> = { Mirim: 1, FAC: 2, Juvenil: 3, "Sênior": 4 };

	function unlockedServoCategories(names: string[]): string[] {
		const unlocked: string[] = [];
		let maxRank = 0;
		for (const name of names) {
			if (CATEGORY_CHAIN[name]) maxRank = Math.max(maxRank, CATEGORY_CHAIN[name]);
			else unlocked.push(name);
		}
		for (const [name, rank] of Object.entries(CATEGORY_CHAIN)) {
			if (rank <= maxRank) unlocked.push(name);
		}
		return unlocked;
	}

	let activity: any = $state(null);
	let loading = $state(true);
	let error = $state("");
	let minhaInscricao = $state<MinhaInscricao | null>(null);
	let priorCategoryNames = $state<string[]>([]);

	// Subscription State
	let showSubscribeModal = $state(false);
	let pendingTipo: "Campista" | "Participante" | "Servo" = $state("Campista");
	let spouseCpf = $state("");
	let addrStreet = $state("");
	let addrNumber = $state("");
	let addrNeighborhood = $state("");
	let addrCity = $state("");
	let addrCep = $state("");
	let subscribing = $state(false);
	let subscribeError = $state("");

	async function loadMinhaInscricao(activityId: number) {
		const { inscricoes } = await getMinhasInscricoes();
		minhaInscricao = inscricoes.find((i) => i.activity?.id === activityId) || null;
		priorCategoryNames = Array.from(
			new Set(
				inscricoes
					.filter(
						(i) =>
							i.activity?.id !== activityId &&
							(i.tipo === "Campista" || i.tipo === "Participante") &&
							i.status !== "Desistência" &&
							i.activity?.categoria,
					)
					.map((i) => i.activity!.categoria as string),
			),
		);
	}

	onMount(async () => {
		try {
			const id = $page.params.id;
			const res = await apiFetch(`/activities/${id}`);
			const data = await res.json();
			if (res.ok) {
				activity = data.data;
				await loadMinhaInscricao(Number(id));
				if (
					$page.url.searchParams.get("inscrever") === "1" &&
					activity?.status === "Inscrições Abertas" &&
					!minhaInscricao
				) {
					openSubscribeModal(canBeCampista ? primaryTipo : "Servo");
				}
			} else {
				error = data.error || "Não foi possível carregar os detalhes desta atividade.";
			}
		} catch (e) {
			error = "Falha de comunicação com o servidor.";
		} finally {
			loading = false;
		}
	});

	function openSubscribeModal(tipo: "Campista" | "Participante" | "Servo") {
		pendingTipo = tipo;
		spouseCpf = "";
		addrStreet = "";
		addrNumber = "";
		addrNeighborhood = "";
		addrCity = "";
		addrCep = "";
		subscribeError = "";
		showSubscribeModal = true;
	}

	function isPaymentWindowOpen(deadline?: string | null) {
		if (!deadline) return true;
		return new Date() <= new Date(deadline);
	}

	function inscricaoSlug(status?: string) {
		if (status === "Sorteado" || status === "Indicado" || status === "Formulário Enviado") return "confirmed";
		if (status === "Suplente") return "waiting";
		if (status === "Desistência" || status === "Prazo Perdido") return "cancelled";
		return "pending";
	}

	let isCamping = $derived(activity?.tipo === "Acampamento");
	let primaryTipo: "Campista" | "Participante" = $derived(isCamping ? "Campista" : "Participante");
	let isCasais = $derived(
		activity?.categoria?.nome === "Casais" || activity?.targetAudience === "Casais",
	);
	let needsSpouseCpf = $derived.by(() => {
		if (isCasais && pendingTipo !== "Servo") return true;
		return ["Casado", "União estável"].includes(auth.user?.marital_status?.title);
	});
	let needsAddress = $derived(!auth.user?.address);

	// A hierarquia por categoria só vale para Acampamentos — Eventos ficam sempre liberados.
	let currentCategoryName = $derived(activity?.categoria?.nome ?? null);
	let canBeCampista = $derived(
		!isCamping || !currentCategoryName || !priorCategoryNames.includes(currentCategoryName),
	);
	let canBeServo = $derived(
		!isCamping ||
			(!!currentCategoryName && unlockedServoCategories(priorCategoryNames).includes(currentCategoryName)),
	);
	let isParticipantSubscriber = $derived(
		minhaInscricao?.tipo === "Campista" || minhaInscricao?.tipo === "Participante",
	);
	let isServoSubscriber = $derived(minhaInscricao?.tipo === "Servo");
	let participantPaymentVisible = $derived(
		isParticipantSubscriber && isPaymentWindowOpen(activity?.prazoPagamentoParticipante),
	);
	let servoPaymentVisible = $derived(isServoSubscriber && isPaymentWindowOpen(activity?.prazoPagamentoServo));
	let statusSlug = $derived(
		activity?.status === "Inscrições Abertas"
			? "open"
			: activity?.status === "Encerrado"
				? "closed"
				: "soon",
	);

	function formatDate(dateString?: string | null) {
		if (!dateString) return "A definir";
		return new Date(dateString).toLocaleString("pt-BR", {
			day: "2-digit",
			month: "2-digit",
			year: "numeric",
			hour: "2-digit",
			minute: "2-digit",
		}).replace(",", " às");
	}

	function formatCurrency(val?: number | string | null) {
		if (!val || Number(val) === 0) return "Gratuito";
		return Number(val).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
	}

	// The backend defaults notice/term to a "#" placeholder when nothing was uploaded.
	function isRealLink(url?: string | null) {
		return !!url && !url.endsWith("/#");
	}

	async function handleSubscribe(e: Event) {
		e.preventDefault();
		subscribing = true;
		subscribeError = "";

		try {
			const body = new FormData();
			body.append("tipo", pendingTipo);
			if (spouseCpf) body.append("spouse_cpf", spouseCpf);
			if (needsAddress) {
				body.append("street", addrStreet);
				body.append("number", addrNumber);
				body.append("neighborhood", addrNeighborhood);
				body.append("city", addrCity);
				body.append("cep", addrCep);
			}

			const res = await apiFetch(`/activities/${$page.params.id}/inscricoes`, {
				method: "POST",
				body,
			});
			const data = await res.json();

			if (res.ok) {
				showSubscribeModal = false;
				await loadMinhaInscricao(Number($page.params.id));
			} else {
				subscribeError = data.error || "Erro ao processar inscrição.";
			}
		} catch (err) {
			subscribeError = "Falha de comunicação com o servidor.";
		} finally {
			subscribing = false;
		}
	}
</script>

<svelte:head>
	<title>{activity ? activity.nome : "Carregando..."} | Detalhes</title>
</svelte:head>

<div class="page-container">
	{#if loading}
		<div class="loading-state">
			<div class="spinner"></div>
			<p>Carregando informações...</p>
		</div>
	{:else if error}
		<div class="error-state">
			<p>{error}</p>
			<a href="/dashboard" class="back-link-btn">Voltar ao Painel</a>
		</div>
	{:else if activity}
		<!-- Navegação -->
		<div class="nav-bar">
			<a href="/dashboard" class="back-link">← Voltar para Painel</a>
		</div>

		<!-- Hero Banner -->
		<div
			class="hero-banner"
			style={activity.imagem
				? `background-image: linear-gradient(to bottom, rgba(0,0,0,0.1), rgba(0,0,0,0.8)), url('${activity.imagem}');`
				: "background: linear-gradient(to bottom, #111827, #374151);"}
		>
			<div class="hero-content">
				<div class="tags">
					<span class="tag type-tag">{isCamping ? "🏕️ Acampamento" : "🎉 Evento"}</span>
					{#if activity.categoria}
						<span class="tag category-tag">{activity.categoria.nome}</span>
					{:else if activity.targetAudience}
						<span class="tag category-tag">{activity.targetAudience}</span>
					{/if}
					<span class="tag status-tag status-{statusSlug}">{activity.status}</span>
				</div>
				<h1 class="title">{activity.nome}</h1>
				<div class="meta-info">
					<span class="meta-item">📍 {activity.local || "Local a definir"}</span>
					<span class="meta-item">
						📅 {activity.inicioEvento ? new Date(activity.inicioEvento).toLocaleDateString("pt-BR") : "Data a definir"}
						{#if activity.fimEvento && activity.fimEvento !== activity.inicioEvento}
							até {new Date(activity.fimEvento).toLocaleDateString("pt-BR")}
						{/if}
					</span>
					{#if activity.vagasTotais}
						<span class="meta-item">👥 {activity.vagasTotais} vagas</span>
					{/if}
				</div>

				<div style="margin-top: 2rem;">
					{#if minhaInscricao}
						<div class="subscribed-banner subscribed-{inscricaoSlug(minhaInscricao.status)}">
							<div class="subscribed-banner-icon">✓</div>
							<div>
								<strong>Você já está inscrito(a) neste {isCamping ? "acampamento" : "evento"}!</strong>
								<p>
									Inscrição como <strong>{minhaInscricao.tipo}</strong> · Status atual:
									<strong>{minhaInscricao.status}</strong>
								</p>
								{#if ["Sorteado", "Indicado", "Suplente", "Formulário Enviado"].includes(minhaInscricao.status)}
									<a href="/dashboard/formulario/{minhaInscricao.id}" class="form-link-btn">
										{minhaInscricao.status === "Formulário Enviado" ? "Ver/editar formulário" : "Preencher formulário"}
									</a>
								{/if}
							</div>
						</div>
					{:else if activity.status === "Inscrições Abertas"}
						{#if isCamping && !canBeCampista}
							<p class="veteran-note">
								ℹ️ Você já participou como campista em {currentCategoryName} anteriormente — nesta atividade você só pode se inscrever como servo(a).
							</p>
						{:else if isCamping && !canBeServo && priorCategoryNames.length > 0}
							<p class="veteran-note">
								ℹ️ Você ainda não pode servir em {currentCategoryName}: só é possível servir nas categorias em que já foi campista, ou nas categorias abaixo delas.
							</p>
						{/if}
						<div class="subscribe-actions">
							{#if canBeCampista}
								<button class="subscribe-btn" onclick={() => openSubscribeModal(primaryTipo)}>
									✅ Inscrever-se como {primaryTipo}
								</button>
							{/if}
							{#if canBeServo}
								<button class="subscribe-btn subscribe-btn-servo" onclick={() => openSubscribeModal("Servo")}>
									🙋 Inscrever-se como Servo
								</button>
							{/if}
						</div>
					{:else}
						<button class="subscribe-btn subscribe-btn-disabled" disabled>
							{activity.status === "Encerrado" ? "Inscrições encerradas" : "Inscrições em breve"}
						</button>
					{/if}
				</div>
			</div>
		</div>

		<!-- Content Grid -->
		<div class="content-grid-single">
			<div class="main-column">
				{#if isCamping && (isRealLink(activity.editalEvento) || isRealLink(activity.termoAutorizacao))}
					<section class="info-card highlight-card">
						<h3>Links Importantes</h3>
						<div class="links-grid">
							{#if isRealLink(activity.editalEvento)}
								<a href={activity.editalEvento} target="_blank" class="important-link">📄 Ver Edital Completo</a>
							{/if}
							{#if isRealLink(activity.termoAutorizacao)}
								<a href={activity.termoAutorizacao} target="_blank" class="important-link">✍️ Termo de Responsabilidade</a>
							{/if}
						</div>
					</section>
				{/if}

				{#if isCamping}
					<section class="info-card">
						<h3>Cronograma: Campistas</h3>
						<ul class="timeline">
							{#if activity.inicioSorteio}
								<li>
									<strong>Sorteio Oficial:</strong>
									{formatDate(activity.inicioSorteio)}
								</li>
							{/if}
							<li>
								<strong>Período de Matrícula:</strong>
								{formatDate(activity.inicioFormulario)} até {formatDate(activity.fimFormulario)}
							</li>
							<li>
								<strong>Taxa de Participação:</strong>
								{formatCurrency(activity.taxaParticipante)}
							</li>
							{#if activity.prazoPagamentoParticipante}
								<li>
									<strong>Prazo de Pagamento:</strong>
									{formatDate(activity.prazoPagamentoParticipante)}
								</li>
							{/if}
							{#if isRealLink(activity.linkPagamentoParticipante) && participantPaymentVisible}
								<li>
									<a href={activity.linkPagamentoParticipante} target="_blank" class="important-link inline-link">💳 Link de Pagamento</a>
								</li>
							{:else if isRealLink(activity.linkPagamentoParticipante) && isParticipantSubscriber}
								<li><span class="text-muted-inline">Prazo de pagamento encerrado.</span></li>
							{:else if isRealLink(activity.linkPagamentoParticipante) && !minhaInscricao}
								<li><span class="text-muted-inline">Faça sua inscrição como campista para liberar o link de pagamento.</span></li>
							{/if}
						</ul>
					</section>

					<section class="info-card secondary-bg">
						<h3>Cronograma: Servos</h3>
						<ul class="timeline">
							<li>
								<strong>Inscrições para o sorteio:</strong>
								{formatDate(activity.inicioInscricoesServos)} até {formatDate(activity.fimInscricoesServos)}
							</li>
							{#if activity.inicioSorteioServo}
								<li>
									<strong>Sorteio (se houver):</strong>
									{formatDate(activity.inicioSorteioServo)}
								</li>
							{/if}
							<li>
								<strong>Confirmação de Vaga:</strong>
								{formatDate(activity.inicioInscricoesServo)} até {formatDate(activity.fimInscricoesServo)}
							</li>
							<li>
								<strong>Taxa de Participação:</strong>
								{formatCurrency(activity.taxaServo)}
							</li>
							{#if activity.prazoPagamentoServo}
								<li>
									<strong>Prazo de Pagamento:</strong>
									{formatDate(activity.prazoPagamentoServo)}
								</li>
							{/if}
							{#if isRealLink(activity.linkPagamentoServo) && servoPaymentVisible}
								<li>
									<a href={activity.linkPagamentoServo} target="_blank" class="important-link inline-link">💳 Link de Pagamento</a>
								</li>
							{:else if isRealLink(activity.linkPagamentoServo) && isServoSubscriber}
								<li><span class="text-muted-inline">Prazo de pagamento encerrado.</span></li>
							{:else if isRealLink(activity.linkPagamentoServo) && !minhaInscricao}
								<li><span class="text-muted-inline">Faça sua inscrição como servo(a) para liberar o link de pagamento.</span></li>
							{/if}
						</ul>
					</section>
				{:else}
					<section class="info-card">
						<h3>Cronograma de Inscrições</h3>
						<ul class="timeline">
							<li>
								<strong>Período de Inscrição:</strong>
								{formatDate(activity.inicioInscricoes)} até {formatDate(activity.fimInscricoes)}
							</li>
						</ul>
					</section>

					<section class="info-card highlight-card">
						<h3>Investimento</h3>
						<div class="price-box">
							<span class="price-label">Ingresso</span>
							<span class="price-value">{formatCurrency(activity.taxaParticipante)}</span>
						</div>
						{#if activity.prazoPagamentoParticipante}
							<p class="payment-deadline">Prazo de pagamento: {formatDate(activity.prazoPagamentoParticipante)}</p>
						{/if}
						{#if isRealLink(activity.linkPagamentoParticipante) && participantPaymentVisible}
							<a href={activity.linkPagamentoParticipante} target="_blank" class="pay-btn">Link de Pagamento</a>
						{:else if isRealLink(activity.linkPagamentoParticipante) && !minhaInscricao}
							<p class="text-muted-inline">Faça sua inscrição para liberar o link de pagamento.</p>
						{:else if isRealLink(activity.linkPagamentoParticipante) && isParticipantSubscriber}
							<p class="text-muted-inline">Prazo de pagamento encerrado.</p>
						{/if}
					</section>
				{/if}
			</div>
		</div>
	{/if}

	<!-- Subscription Modal -->
	{#if showSubscribeModal && activity}
		<div class="modal-overlay">
			<div class="modal-content">
				<h3>{pendingTipo === "Servo" ? "Confirmar Inscrição como Servo" : "Confirmar Inscrição"}</h3>
				<p>Você está prestes a se inscrever em <strong>{activity.nome}</strong>.</p>

				{#if pendingTipo === "Servo"}
					<div class="servo-notice">
						🙋 Você está se inscrevendo como <strong>servo(a)</strong> para ajudar na organização deste {isCamping ? "acampamento" : "evento"}.
					</div>
				{/if}

				{#if subscribeError}
					<div class="alert error-alert" style="margin-bottom: 1rem;">
						{subscribeError}
					</div>
				{/if}

				<form onsubmit={handleSubscribe}>
					{#if needsSpouseCpf}
						<div class="form-group" style="text-align: left; margin-bottom: 1rem;">
							<label for="spouseCpf" style="display:block; margin-bottom: 0.5rem; color: #333; font-weight: 600;">CPF do Cônjuge *</label>
							<input type="text" id="spouseCpf" bind:value={spouseCpf} required placeholder="Digite o CPF (apenas números)" class="form-input" />
							<small style="color: #666; display: block; margin-top: 0.4rem;">Seu cônjuge deve ter uma conta no sistema para você vinculá-lo.</small>
						</div>
					{/if}

					{#if needsAddress}
						<div class="form-group" style="text-align: left; margin-bottom: 1rem;">
							<span style="display:block; margin-bottom: 0.5rem; color: #333; font-weight: 600;">Endereço *</span>
							<small style="color: #666; display: block; margin-bottom: 0.6rem;">Precisamos do seu endereço pra saber sua elegibilidade no sorteio.</small>
							<input type="text" bind:value={addrStreet} required placeholder="Rua" class="form-input" style="margin-bottom: 0.5rem;" />
							<div style="display:flex; gap: 0.5rem; margin-bottom: 0.5rem;">
								<input type="text" bind:value={addrNumber} required placeholder="Número" class="form-input" style="flex: 1;" />
								<input type="text" bind:value={addrCep} required placeholder="CEP" class="form-input" style="flex: 1;" />
							</div>
							<input type="text" bind:value={addrNeighborhood} required placeholder="Bairro" class="form-input" style="margin-bottom: 0.5rem;" />
							<input type="text" bind:value={addrCity} required placeholder="Cidade" class="form-input" />
						</div>
					{/if}

					<div class="modal-actions">
						<button type="button" class="cancel-btn" onclick={() => (showSubscribeModal = false)} disabled={subscribing}>Cancelar</button>
						<button type="submit" class="confirm-btn" disabled={subscribing}>
							{subscribing ? "Processando..." : "Confirmar Inscrição"}
						</button>
					</div>
				</form>
			</div>
		</div>
	{/if}
</div>

<style>
	:global(body) {
		background-color: #f8f9fa;
		margin: 0;
		font-family: 'Inter', system-ui, sans-serif;
	}

	.page-container {
		max-width: 1200px;
		margin: 0 auto;
		padding: 2rem;
		min-height: 100vh;
	}

	.nav-bar {
		margin-bottom: 1.5rem;
	}

	.back-link {
		color: #4b5563;
		text-decoration: none;
		font-weight: 600;
		font-size: 0.95rem;
		transition: color 0.2s;
	}

	.back-link:hover {
		color: #111827;
	}

	/* Hero Banner */
	.hero-banner {
		border-radius: 20px;
		min-height: 350px;
		background-size: cover;
		background-position: center;
		display: flex;
		align-items: flex-end;
		padding: 3rem;
		margin-bottom: 2.5rem;
		box-shadow: 0 10px 30px rgba(0,0,0,0.15);
		color: white;
	}

	.hero-content {
		max-width: 800px;
	}

	.tags {
		display: flex;
		gap: 0.8rem;
		flex-wrap: wrap;
		margin-bottom: 1rem;
	}

	.tag {
		padding: 0.4rem 1rem;
		border-radius: 30px;
		font-size: 0.85rem;
		font-weight: 700;
		text-transform: uppercase;
		letter-spacing: 0.05em;
	}

	.type-tag {
		background: rgba(255, 255, 255, 0.2);
		backdrop-filter: blur(10px);
		border: 1px solid rgba(255, 255, 255, 0.3);
	}

	.category-tag {
		background: #6fdec2;
		color: #111;
	}

	.status-tag {
		color: #fff;
	}

	.status-tag.status-open {
		background: #10b981;
	}

	.status-tag.status-closed {
		background: #6b7280;
	}

	.status-tag.status-soon {
		background: #b69e55;
	}

	.title {
		font-size: 3rem;
		font-weight: 800;
		margin: 0 0 1rem 0;
		line-height: 1.2;
		text-shadow: 0 2px 4px rgba(0,0,0,0.5);
	}

	.meta-info {
		display: flex;
		gap: 2rem;
		flex-wrap: wrap;
		font-size: 1.05rem;
		font-weight: 500;
		text-shadow: 0 1px 2px rgba(0,0,0,0.5);
	}

	/* Grid Layout */
	.content-grid-single {
		display: block;
		width: 100%;
	}

	@media (max-width: 900px) {
		.hero-banner {
			padding: 2rem;
			min-height: 250px;
		}
		.title {
			font-size: 2rem;
		}
		.meta-info {
			flex-direction: column;
			gap: 0.5rem;
		}
	}

	/* Cards */
	.info-card {
		background: white;
		border-radius: 16px;
		padding: 2rem;
		margin-bottom: 2rem;
		box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
		border: 1px solid #f3f4f6;
	}

	.highlight-card {
		background: linear-gradient(to right, #ffffff, #f0fdfa);
		border-left: 4px solid #10b981;
	}

	.secondary-bg {
		background: #f8fafc;
	}

	.info-card h3 {
		margin: 0 0 1.5rem 0;
		font-size: 1.3rem;
		color: #111827;
		border-bottom: 2px solid #f3f4f6;
		padding-bottom: 0.8rem;
	}

	/* Timeline */
	.timeline {
		list-style: none;
		padding: 0;
		margin: 0;
		display: flex;
		flex-direction: column;
		gap: 1.2rem;
	}

	.timeline li {
		position: relative;
		padding-left: 1.5rem;
		color: #4b5563;
		line-height: 1.5;
	}

	.timeline li::before {
		content: '';
		position: absolute;
		left: 0;
		top: 0.5rem;
		width: 8px;
		height: 8px;
		background: #6fdec2;
		border-radius: 50%;
	}

	.timeline strong {
		color: #111827;
		display: block;
		font-size: 0.95rem;
		margin-bottom: 0.2rem;
	}

	/* Pricing */
	.price-box {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 1rem;
	}

	.price-label {
		font-weight: 600;
		color: #4b5563;
		font-size: 1.1rem;
	}

	.price-value {
		font-size: 1.5rem;
		font-weight: 800;
		color: #10b981;
	}

	.payment-deadline {
		color: #6b7280;
		font-size: 0.9rem;
		margin: 0 0 1rem 0;
	}

	/* Buttons & Links */
	.links-grid {
		display: flex;
		gap: 1rem;
		flex-wrap: wrap;
	}

	.important-link {
		display: inline-flex;
		align-items: center;
		padding: 0.8rem 1.5rem;
		background: white;
		border: 1px solid #d1d5db;
		border-radius: 8px;
		color: #111827;
		text-decoration: none;
		font-weight: 600;
		transition: all 0.2s;
	}

	.important-link.inline-link {
		padding: 0.5rem 1rem;
		font-size: 0.9rem;
	}

	.important-link:hover {
		border-color: #6fdec2;
		box-shadow: 0 4px 12px rgba(111, 222, 194, 0.15);
		transform: translateY(-2px);
	}

	.pay-btn {
		display: inline-block;
		text-align: center;
		background: #111827;
		color: #6fdec2;
		padding: 0.8rem 1.5rem;
		border-radius: 8px;
		text-decoration: none;
		font-weight: 700;
		transition: all 0.2s;
	}

	.pay-btn:hover {
		background: #1f2937;
		color: white;
		transform: translateY(-2px);
	}

	/* Loaders */
	.loading-state, .error-state {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		min-height: 50vh;
		color: #6b7280;
		gap: 1rem;
	}

	.back-link-btn {
		color: #1c8b6b;
		font-weight: 700;
		text-decoration: none;
	}

	.spinner {
		width: 40px;
		height: 40px;
		border: 4px solid #f3f3f3;
		border-top: 4px solid #6fdec2;
		border-radius: 50%;
		animation: spin 1s linear infinite;
		margin-bottom: 1rem;
	}

	@keyframes spin {
		0% { transform: rotate(0deg); }
		100% { transform: rotate(360deg); }
	}

	/* Subscribe Button and Modal Styles */
	.subscribe-btn {
		background: #6fdec2;
		color: #111;
		font-size: 1.15rem;
		font-weight: 800;
		padding: 1.2rem 2.5rem;
		border: none;
		border-radius: 12px;
		cursor: pointer;
		transition: all 0.3s;
		box-shadow: 0 4px 15px rgba(111, 222, 194, 0.4);
		text-transform: uppercase;
		letter-spacing: 0.05em;
	}

	.subscribe-btn:hover {
		transform: translateY(-3px) scale(1.02);
		box-shadow: 0 8px 25px rgba(111, 222, 194, 0.6);
		background: #5bc8ac;
	}

	.subscribe-btn-disabled {
		background: rgba(255, 255, 255, 0.15);
		color: rgba(255, 255, 255, 0.85);
		box-shadow: none;
		cursor: not-allowed;
	}

	.subscribe-btn-disabled:hover {
		transform: none;
		box-shadow: none;
		background: rgba(255, 255, 255, 0.15);
	}

	.subscribe-actions {
		display: flex;
		flex-wrap: wrap;
		gap: 1rem;
	}

	.subscribe-btn-servo {
		background: rgba(255, 255, 255, 0.12);
		color: #fff;
		border: 1.5px solid rgba(255, 255, 255, 0.5);
		backdrop-filter: blur(6px);
		box-shadow: none;
	}

	.subscribe-btn-servo:hover {
		background: rgba(255, 255, 255, 0.22);
		transform: translateY(-2px);
		box-shadow: none;
	}

	.veteran-note {
		color: rgba(255, 255, 255, 0.85);
		font-size: 0.92rem;
		margin: 0 0 1rem 0;
		max-width: 46ch;
	}

	.servo-notice {
		background: #eff6ff;
		color: #1d4ed8;
		border: 1px solid #bfdbfe;
		padding: 0.9rem 1.1rem;
		border-radius: 10px;
		font-size: 0.92rem;
		margin-bottom: 1.25rem;
		text-align: left;
	}

	.text-muted-inline {
		color: #9ca3af;
		font-style: italic;
		font-size: 0.9rem;
	}

	/* Already-subscribed banner */
	.subscribed-banner {
		display: flex;
		align-items: flex-start;
		gap: 1rem;
		max-width: 560px;
		padding: 1.2rem 1.5rem;
		border-radius: 14px;
		background: rgba(255, 255, 255, 0.95);
		backdrop-filter: blur(8px);
		box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
		border-left: 5px solid #10b981;
	}

	.subscribed-banner strong {
		color: #111827;
	}

	.subscribed-banner p {
		margin: 0.3rem 0 0 0;
		color: #4b5563;
		font-size: 0.92rem;
	}

	.form-link-btn {
		display: inline-block;
		margin-top: 0.8rem;
		background: #115843;
		color: #fff;
		text-decoration: none;
		padding: 0.55rem 1.2rem;
		border-radius: 999px;
		font-weight: 700;
		font-size: 0.85rem;
	}

	.subscribed-banner-icon {
		flex-shrink: 0;
		width: 2.5rem;
		height: 2.5rem;
		border-radius: 50%;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 1.3rem;
		font-weight: 800;
		color: #fff;
		background: #10b981;
	}

	.subscribed-banner.subscribed-waiting {
		border-left-color: #b69e55;
	}

	.subscribed-banner.subscribed-waiting .subscribed-banner-icon {
		background: #b69e55;
	}

	.subscribed-banner.subscribed-pending {
		border-left-color: #3b82f6;
	}

	.subscribed-banner.subscribed-pending .subscribed-banner-icon {
		background: #3b82f6;
	}

	.subscribed-banner.subscribed-cancelled {
		border-left-color: #6b7280;
	}

	.subscribed-banner.subscribed-cancelled .subscribed-banner-icon {
		background: #6b7280;
	}

	.modal-overlay {
		position: fixed;
		top: 0; left: 0; right: 0; bottom: 0;
		background: rgba(0,0,0,0.6);
		display: flex;
		align-items: center;
		justify-content: center;
		z-index: 1000;
		backdrop-filter: blur(4px);
	}

	.modal-content {
		background: white;
		padding: 2.5rem;
		border-radius: 16px;
		width: 90%;
		max-width: 500px;
		text-align: center;
		color: #111;
		box-shadow: 0 10px 40px rgba(0,0,0,0.2);
	}

	.modal-content h3 { margin-top: 0; font-size: 1.5rem; }
	.modal-content p { color: #4b5563; margin-bottom: 1.5rem; font-size: 1.05rem; }

	.alert.error-alert {
		background: #fef2f2;
		color: #991b1b;
		border: 1px solid #fecaca;
		padding: 0.85rem 1rem;
		border-radius: 8px;
		font-size: 0.9rem;
	}

	.form-input {
		width: 100%;
		padding: 0.8rem 1rem;
		border: 1px solid #d1d5db;
		border-radius: 8px;
		font-size: 1rem;
		transition: border-color 0.2s;
	}

	.form-input:focus {
		outline: none;
		border-color: #6fdec2;
		box-shadow: 0 0 0 3px rgba(111, 222, 194, 0.2);
	}

	.modal-actions {
		display: flex;
		justify-content: flex-end;
		gap: 1rem;
		margin-top: 2rem;
	}

	.cancel-btn {
		padding: 0.8rem 1.5rem;
		border: 1px solid #d1d5db;
		background: transparent;
		border-radius: 8px;
		cursor: pointer;
		font-weight: 600;
		color: #4b5563;
		transition: background 0.2s;
	}

	.cancel-btn:hover { background: #f3f4f6; }

	.confirm-btn {
		padding: 0.8rem 1.5rem;
		border: none;
		background: #111827;
		color: white;
		border-radius: 8px;
		cursor: pointer;
		font-weight: 600;
		transition: all 0.2s;
	}

	.confirm-btn:hover:not(:disabled) { background: #1f2937; transform: translateY(-1px); }
	.confirm-btn:disabled { opacity: 0.7; cursor: not-allowed; }
</style>
