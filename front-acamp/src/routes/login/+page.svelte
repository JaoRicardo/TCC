<script lang="ts">
	import { apiFetch } from "$lib/api";
	import { auth } from "$lib/stores/auth.svelte";
	import { goto } from "$app/navigation";
	import { page } from "$app/stores";
	import { atividadeDaInscricao, destinoSeguro, registroUrl } from "$lib/redirect";
	import { mascaraCpf } from "$lib/mascaras";
	import AcessoLayout from "$lib/components/AcessoLayout.svelte";

	// Para onde voltar depois de entrar (ex.: a atividade em que a pessoa clicou
	// "Inscrever-se"). Sem destino, vai para a lista de atividades.
	let destino = $derived(destinoSeguro($page.url.searchParams.get("redirect")));
	let atividadeId = $derived(atividadeDaInscricao(destino));

	interface LoginResponse {
		access_token?: string;
		refresh_token?: string;
		user?: any;
		error?: string;
	}

	let cpf = $state("");
	let senha = $state("");
	let isLoading = $state(false);
	let errorMessage = $state("");
	let showPassword = $state(false);

	// Nome da atividade da "Inscrição em andamento" (os detalhes são públicos).
	let atividadeNome = $state("");
	$effect(() => {
		if (!atividadeId) return;
		apiFetch(`/activities/${atividadeId}`)
			.then((res) => (res.ok ? res.json() : null))
			.then((data) => (atividadeNome = data?.data?.nome ?? ""))
			.catch(() => {});
	});

	// O erro some quando a pessoa volta a digitar.
	function digitou() {
		errorMessage = "";
	}

	async function handleSubmit(e: Event) {
		e.preventDefault();
		errorMessage = "";

		const cleanCpf = cpf.replace(/\D/g, "");
		if (!cleanCpf || !senha) {
			errorMessage = "Preencha o CPF e a senha.";
			return;
		}

		isLoading = true;

		try {
			const response = await apiFetch("/auth/login", {
				method: "POST",
				body: JSON.stringify({
					cpf: cleanCpf,
					password: senha,
				}),
			});

			const data: LoginResponse = await response.json();

			if (response.ok) {
				auth.setToken(data.access_token!);
				auth.user = data.user;
				await auth.refreshUser();
				await goto(destino ?? "/dashboard");
			} else {
				errorMessage =
					data.error ||
					"Erro ao realizar login. Verifique suas credenciais.";
			}
		} catch (error) {
			console.error("Erro na requisição:", error);
			errorMessage = "Erro de conexão com o servidor.";
		} finally {
			isLoading = false;
		}
	}
</script>

<svelte:head>
	<title>Login | Acampamento São Miguel</title>
</svelte:head>

<AcessoLayout variante="login">
	<form class="ac-form" onsubmit={handleSubmit} novalidate>
		{#if atividadeId}
			<div class="ac-inscricao">
				<span class="ac-inscricao-rotulo">Inscrição em andamento</span>
				{#if atividadeNome}
					<span class="ac-inscricao-nome">{atividadeNome}</span>
				{/if}
				<span>Entre para concluir sua inscrição.</span>
			</div>
		{/if}

		<div class="ac-titulos">
			<h1 class="ac-titulo">Bem-vindo(a)</h1>
			<p class="ac-subtitulo">Entre com seu CPF e sua senha.</p>
			<div class="ac-filete"><span></span></div>
		</div>

		{#if errorMessage}
			<div class="ac-erro" role="alert">
				<span class="ac-erro-icone">!</span>
				<span>{errorMessage}</span>
			</div>
		{/if}

		<div class="ac-campo">
			<label for="cpf" class="ac-rotulo">CPF</label>
			<input
				id="cpf"
				name="cpf"
				class="ac-input"
				inputmode="numeric"
				autocomplete="username"
				placeholder="000.000.000-00"
				value={cpf}
				oninput={(e) => {
					cpf = mascaraCpf(e.currentTarget.value);
					e.currentTarget.value = cpf;
					digitou();
				}}
				disabled={isLoading}
				aria-invalid={errorMessage ? "true" : undefined}
			/>
		</div>

		<div class="ac-campo">
			<label for="senha" class="ac-rotulo">Senha</label>
			<div class="ac-senha">
				<input
					id="senha"
					name="senha"
					class="ac-input"
					type={showPassword ? "text" : "password"}
					autocomplete="current-password"
					placeholder="Sua senha"
					bind:value={senha}
					oninput={digitou}
					disabled={isLoading}
					aria-invalid={errorMessage ? "true" : undefined}
				/>
				<button
					type="button"
					class="ac-mostrar"
					onclick={() => (showPassword = !showPassword)}
					aria-label={showPassword ? "Ocultar senha" : "Mostrar senha"}
				>
					{showPassword ? "Ocultar" : "Mostrar"}
				</button>
			</div>
		</div>

		<button type="submit" class="ac-btn-principal" disabled={isLoading}>
			{#if isLoading}
				<span class="ac-spinner"></span>Carregando...
			{:else}
				Entrar
			{/if}
		</button>

		<p class="ac-rodape">
			Ainda não tem conta? <a href={registroUrl(destino)}>Registre-se aqui</a>
		</p>
	</form>
</AcessoLayout>
