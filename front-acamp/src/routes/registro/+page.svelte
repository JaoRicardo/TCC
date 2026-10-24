<script lang="ts">
	import { onMount } from "svelte";
	import logo from "$lib/imgs/logo_saomiguel - Copy.png";
	import { apiFetch } from "$lib/api";
	import { goto } from "$app/navigation";

	let step = $state(1);
	let isLoading = $state(false);
	let errorMessage = $state("");
	let successMessage = $state("");

	// Formulário
	let name = $state("");
	let cpf = $state("");
	let birthday = $state("");
	let sex = $state("");
	let marital_status_id = $state("");
	let email = $state("");
	let phone = $state("");
	let password = $state("");
	let password_confirmation = $state("");

	let maritalStatuses: { id: number; title: string }[] = $state([]);

	onMount(async () => {
		try {
			const response = await apiFetch("/marital-statuses");
			if (response.ok) {
				const resData = await response.json();
				maritalStatuses = Array.isArray(resData)
					? resData
					: resData.data || [];
			}
		} catch (error) {
			console.error("Erro ao carregar estados civis", error);
		}
	});

	function cpfMask(node: HTMLInputElement) {
		function handleInput() {
			let value = node.value.replace(/\D/g, "");
			value = value.replace(/(\d{3})(\d)/, "$1.$2");
			value = value.replace(/(\d{3})(\d)/, "$1.$2");
			value = value.replace(/(\d{3})(\d{1,2})/, "$1-$2");
			node.value = value.replace(/(-\d{2})\d+?$/, "$1");
			cpf = node.value;
		}
		node.addEventListener("input", handleInput);
		return {
			destroy() {
				node.removeEventListener("input", handleInput);
			},
		};
	}

	function phoneMask(node: HTMLInputElement) {
		function handleInput() {
			let value = node.value.replace(/\D/g, "");
			value = value.replace(/^(\d{2})(\d)/g, "($1) $2");
			value = value.replace(/(\d)(\d{4})$/, "$1-$2");
			node.value = value.substring(0, 15);
			phone = node.value;
		}
		node.addEventListener("input", handleInput);
		return {
			destroy() {
				node.removeEventListener("input", handleInput);
			},
		};
	}

	function nextStep() {
		errorMessage = "";
		if (!name || !cpf || !birthday || !sex || !marital_status_id) {
			errorMessage = "Por favor, preencha todos os campos desta etapa.";
			return;
		}
		step = 2;
	}

	function prevStep() {
		errorMessage = "";
		step = 1;
	}

	async function handleSubmit(e: Event) {
		e.preventDefault();
		errorMessage = "";

		if (password !== password_confirmation) {
			errorMessage = "As senhas não coincidem.";
			return;
		}

		isLoading = true;

		const cleanCpf = cpf.replace(/\D/g, "");
		const cleanPhone = phone.replace(/\D/g, "");

		try {
			const response = await apiFetch("/users", {
				method: "POST",
				body: JSON.stringify({
					name,
					cpf: cleanCpf,
					birthday,
					sex,
					marital_status_id: Number(marital_status_id),
					email,
					phone: cleanPhone,
					password,
					password_confirmation,
					photo: "https://example.com/foto.jpg",
				}),
			});

			const data = await response.json();

			if (response.ok) {
				successMessage = "Conta criada com sucesso! Redirecionando...";
				setTimeout(() => {
					goto("/login");
				}, 2000);
			} else {
				errorMessage =
					data.error || data.message || "Erro ao realizar cadastro.";
				if (data.errors && Object.keys(data.errors).length > 0) {
					const firstErrorKey = Object.keys(data.errors)[0];
					const firstFieldErrors = data.errors[firstErrorKey];
					errorMessage = Object.values(firstFieldErrors)[0] as string;
				}
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
	<title>Registro | Acampamento São Miguel</title>
</svelte:head>

<div class="login-container">
	<div class="login-card">
		<div class="login-left">
			<img src={logo} alt="Logo São Miguel" class="logo-mobile" />
			<h1 class="title">CRIAR CONTA</h1>

			{#if successMessage}
				<div class="success-message">
					{successMessage}
				</div>
			{:else}
				<form
					onsubmit={step === 2
						? handleSubmit
						: (e) => {
								e.preventDefault();
								nextStep();
							}}
					class="login-form"
				>
					{#if errorMessage}
						<div
							class="error-message"
							role="alert"
							aria-live="assertive"
						>
							{errorMessage}
						</div>
					{/if}

					{#if step === 1}
						<div class="input-group">
							<label for="name" class="sr-only"
								>Nome Completo</label
							>
							<input
								type="text"
								id="name"
								placeholder="Nome Completo"
								bind:value={name}
								maxlength="255"
								required
								aria-invalid={errorMessage ? "true" : undefined}
							/>
						</div>

						<div class="input-group">
							<label for="cpf" class="sr-only">CPF</label>
							<input
								type="text"
								id="cpf"
								placeholder="CPF"
								maxlength="14"
								bind:value={cpf}
								use:cpfMask
								required
								aria-invalid={errorMessage ? "true" : undefined}
							/>
						</div>

						<div class="input-group">
							<label for="birthday" class="sr-only"
								>Data de Nascimento</label
							>
							<input
								type="text"
								onfocus={(e) => (e.currentTarget.type = "date")}
								onblur={(e) => {
									if (!e.currentTarget.value)
										e.currentTarget.type = "text";
								}}
								id="birthday"
								placeholder="Data de Nascimento"
								bind:value={birthday}
								required
								aria-invalid={errorMessage ? "true" : undefined}
							/>
						</div>

						<div class="row-group">
							<div class="input-group flex-1">
								<label for="sex" class="sr-only">Sexo</label>
								<select
									id="sex"
									bind:value={sex}
									required
									aria-invalid={errorMessage
										? "true"
										: undefined}
								>
									<option value="" disabled selected
										>Sexo</option
									>
									<option value="M">Masculino</option>
									<option value="F">Feminino</option>
								</select>
							</div>

							<div class="input-group flex-1">
								<label for="marital_status_id" class="sr-only"
									>Estado Civil</label
								>
								<select
									id="marital_status_id"
									bind:value={marital_status_id}
									required
									aria-invalid={errorMessage
										? "true"
										: undefined}
								>
									<option value="" disabled selected
										>Estado Civil</option
									>
									{#each maritalStatuses as status}
										<option value={status.id}
											>{status.title}</option
										>
									{/each}
								</select>
							</div>
						</div>

						<button type="submit" class="login-button">
							Próximo
						</button>
					{:else if step === 2}
						<div class="input-group">
							<label for="email" class="sr-only">E-mail</label>
							<input
								type="email"
								id="email"
								placeholder="E-mail"
								bind:value={email}
								required
								aria-invalid={errorMessage ? "true" : undefined}
							/>
						</div>

						<div class="input-group">
							<label for="phone" class="sr-only">Telefone</label>
							<input
								type="text"
								id="phone"
								placeholder="Telefone"
								bind:value={phone}
								use:phoneMask
								maxlength="15"
								required
								aria-invalid={errorMessage ? "true" : undefined}
							/>
						</div>

						<div class="input-group">
							<label for="password" class="sr-only">Senha</label>
							<input
								type="password"
								id="password"
								placeholder="Senha"
								bind:value={password}
								required
								aria-invalid={errorMessage ? "true" : undefined}
							/>
						</div>

						<div class="input-group">
							<label for="password_confirmation" class="sr-only"
								>Confirmar Senha</label
							>
							<input
								type="password"
								id="password_confirmation"
								placeholder="Confirmar Senha"
								bind:value={password_confirmation}
								required
								aria-invalid={errorMessage ? "true" : undefined}
							/>
						</div>

						<div class="button-group">
							<button
								type="button"
								class="login-button secondary"
								onclick={prevStep}
								disabled={isLoading}
							>
								Voltar
							</button>
							<button
								type="submit"
								class="login-button"
								disabled={isLoading}
							>
								{#if isLoading}
									Enviando...
								{:else}
									FINALIZAR
								{/if}
							</button>
						</div>
					{/if}
				</form>
			{/if}

			{#if !successMessage}
				<div class="register-link">
					Já tem uma conta? <a href="/login">Faça login</a>
				</div>
			{/if}
		</div>
		<div class="login-right">
			<img src={logo} alt="Logo São Miguel" class="logo" />
		</div>
	</div>
</div>

<style>
	.sr-only {
		position: absolute;
		width: 1px;
		height: 1px;
		padding: 0;
		margin: -1px;
		overflow: hidden;
		clip: rect(0, 0, 0, 0);
		border: 0;
	}

	.login-container {
		min-height: 100vh;
		display: flex;
		align-items: center;
		justify-content: center;
		background: #f4f7f6;
		padding: 1rem;
	}

	.login-card {
		display: flex;
		width: 100%;
		max-width: 900px;
		background: #ffffff;
		border-radius: 20px;
		box-shadow: 0 20px 50px rgba(0, 0, 0, 0.1);
		overflow: hidden;
		min-height: 550px;
	}

	.login-left {
		flex: 1;
		background: #6fdec2;
		padding: 3.5rem 3rem;
		display: flex;
		flex-direction: column;
		justify-content: center;
		color: #ffffff;
	}

	.login-right {
		flex: 1;
		background: #ffffff;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		padding: 2rem;
		position: relative;
	}

	.logo-mobile {
		display: none;
		width: 120px;
		margin: 0 auto 2rem;
		filter: brightness(0) invert(1);
	}

	.title {
		font-size: 2.2rem;
		font-weight: 800;
		text-transform: uppercase;
		text-align: center;
		margin-bottom: 2.5rem;
		letter-spacing: 2px;
	}

	.login-form {
		display: flex;
		flex-direction: column;
		gap: 1.25rem;
		width: 100%;
		max-width: 340px;
		margin: 0 auto;
	}

	.row-group {
		display: flex;
		gap: 0.75rem;
	}

	.flex-1 {
		flex: 1;
	}

	.error-message {
		background-color: rgba(255, 255, 255, 0.9);
		color: #c62828;
		padding: 0.75rem;
		border-radius: 50px;
		font-size: 0.875rem;
		text-align: center;
		font-weight: 600;
	}

	.success-message {
		background-color: rgba(255, 255, 255, 0.9);
		color: #2e7d32;
		padding: 1rem;
		border-radius: 50px;
		font-size: 1rem;
		text-align: center;
		font-weight: 700;
	}

	.input-group {
		width: 100%;
	}

	.input-group input,
	.input-group select {
		width: 100%;
		padding: 1rem 1.5rem;
		font-size: 1rem;
		border: none;
		border-radius: 50px;
		background: #ffffff;
		color: #333;
		outline: none;
		text-align: center;
		font-weight: 500;
		box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
		transition: box-shadow 0.3s ease;
		font-family: inherit;
		-webkit-appearance: none;
		-moz-appearance: none;
		appearance: none;
	}

	select {
		text-align: center;
		text-align-last: center;
		cursor: pointer;
		background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
		background-repeat: no-repeat;
		background-position: right 1rem center;
		background-size: 1em;
		padding-right: 2.5rem;
	}

	input::placeholder {
		color: #aaa;
		font-weight: 400;
	}

	input:focus,
	select:focus {
		box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.4);
	}

	.button-group {
		display: flex;
		gap: 0.75rem;
		margin-top: 0.5rem;
	}

	.login-button {
		width: 100%;
		padding: 1.1rem;
		font-size: 1.1rem;
		font-weight: 700;
		color: #ffffff;
		background: #b69e55;
		border: none;
		border-radius: 50px;
		cursor: pointer;
		text-transform: uppercase;
		transition: all 0.3s ease;
		box-shadow: 0 4px 15px rgba(182, 158, 85, 0.4);
		margin-top: 0.5rem;
	}

	.login-button:hover:not(:disabled) {
		background: #a8914b;
		transform: translateY(-2px);
		box-shadow: 0 6px 20px rgba(182, 158, 85, 0.5);
	}

	.login-button.secondary {
		background: #f0f0f0;
		color: #333;
		box-shadow: none;
	}

	.login-button.secondary:hover:not(:disabled) {
		background: #e0e0e0;
	}

	.login-button:disabled {
		background: #d4c8a1;
		cursor: not-allowed;
		box-shadow: none;
	}

	.register-link {
		text-align: center;
		font-size: 0.9rem;
		margin-top: 1rem;
	}

	.register-link a {
		color: #ffffff;
		font-weight: 700;
		text-decoration: none;
	}

	.register-link a:hover {
		text-decoration: underline;
	}

	.logo {
		width: 80%;
		max-width: 350px;
		height: auto;
		object-fit: contain;
		filter: drop-shadow(0 10px 20px rgba(0, 0, 0, 0.08));
	}

	@media (max-width: 768px) {
		.login-card {
			flex-direction: column;
			max-width: 420px;
			min-height: auto;
		}

		.login-right {
			display: none;
		}

		.login-left {
			padding: 3rem 2rem;
			border-radius: 20px;
		}

		.logo-mobile {
			display: block;
		}
	}
</style>
