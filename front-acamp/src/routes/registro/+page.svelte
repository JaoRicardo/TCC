<script lang="ts">
	import { onMount } from "svelte";
	import { apiFetch } from "$lib/api";
	import { goto } from "$app/navigation";
	import { page } from "$app/stores";
	import { auth } from "$lib/stores/auth.svelte";
	import { atividadeDaInscricao, destinoSeguro, loginUrl } from "$lib/redirect";
	import { dataParaIso, mascaraCpf, mascaraData, mascaraTelefone } from "$lib/mascaras";
	import AcessoLayout from "$lib/components/AcessoLayout.svelte";

	// Veio do "Inscrever-se" de uma atividade: depois de criar a conta, já entra
	// e volta para lá. Sem destino, segue o fluxo normal (vai para o login).
	let destino = $derived(destinoSeguro($page.url.searchParams.get("redirect")));
	let veioDeInscricao = $derived(atividadeDaInscricao(destino) !== null);
	let linkLogin = $derived(destino ? loginUrl(destino) : "/login");

	type Campo =
		| "name" | "cpf" | "birthday" | "sex" | "marital_status_id"
		| "email" | "phone" | "password" | "password_confirmation";

	// Os nomes são os mesmos da API, para os erros dela marcarem o campo certo.
	const ETAPA_1: Campo[] = ["name", "cpf", "birthday", "sex", "marital_status_id"];
	const ETAPA_2: Campo[] = ["email", "phone", "password", "password_confirmation"];

	let step = $state(1);
	let isLoading = $state(false);
	let errorMessage = $state("");
	let successMessage = $state("");
	/** Quanto falta para sair da tela de sucesso (a barra anda nesse tempo). */
	let tempoRedirecionar = $state(2000);

	let campos = $state<Record<Campo, string>>({
		name: "",
		cpf: "",
		birthday: "", // dd/mm/aaaa na tela; vai em ISO para a API
		sex: "",
		marital_status_id: "",
		email: "",
		phone: "",
		password: "",
		password_confirmation: "",
	});
	let comErro = $state<Campo[]>([]);
	let cpfJaCadastrado = $state(false);
	let mostrarSenha = $state(false);

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

	const MASCARAS: Partial<Record<Campo, (v: string) => string>> = {
		cpf: mascaraCpf,
		birthday: mascaraData,
		phone: mascaraTelefone,
	};

	/** Atualiza o campo (com máscara) e tira a marca de erro dele. */
	function editar(campo: Campo, valor: string) {
		const mascara = MASCARAS[campo];
		campos[campo] = mascara ? mascara(valor) : valor;
		comErro = comErro.filter((c) => c !== campo);
		if (campo === "cpf") cpfJaCadastrado = false;
	}

	function aoDigitar(campo: Campo) {
		return (e: Event & { currentTarget: HTMLInputElement | HTMLSelectElement }) => {
			editar(campo, e.currentTarget.value);
			e.currentTarget.value = campos[campo];
		};
	}

	function temErro(campo: Campo) {
		return comErro.includes(campo) ? "true" : undefined;
	}

	function marcarVazios(lista: Campo[]): boolean {
		const vazios = lista.filter((c) => !campos[c]);
		if (vazios.length) {
			errorMessage = "Por favor, preencha todos os campos desta etapa.";
			comErro = vazios;
			return true;
		}
		return false;
	}

	function nextStep(e: Event) {
		e.preventDefault();
		errorMessage = "";
		comErro = [];
		if (marcarVazios(ETAPA_1)) return;
		if (!dataParaIso(campos.birthday)) {
			errorMessage = "Informe a data de nascimento no formato dd/mm/aaaa.";
			comErro = ["birthday"];
			return;
		}
		step = 2;
	}

	// "Voltar" mantém o que a pessoa já digitou.
	function prevStep() {
		errorMessage = "";
		comErro = [];
		step = 1;
	}

	/** Erro devolvido pela API: volta para a etapa do campo e marca ele. */
	function mostrarErroDaApi(data: any) {
		const erros = data.errors && typeof data.errors === "object" ? data.errors : null;
		const campo = erros ? (Object.keys(erros)[0] as Campo) : null;
		const mensagem = campo ? (Object.values(erros[campo])[0] as string) : null;

		errorMessage = mensagem || data.error || data.message || "Erro ao realizar cadastro.";
		if (!campo) return;

		if (!errorMessage.endsWith(".")) errorMessage += ".";
		comErro = [campo];
		if (ETAPA_1.includes(campo)) step = 1;
		if (campo === "cpf" && mensagem === "CPF já cadastrado") cpfJaCadastrado = true;
	}

	async function handleSubmit(e: Event) {
		e.preventDefault();
		errorMessage = "";
		comErro = [];

		if (marcarVazios(ETAPA_2)) return;
		if (campos.password !== campos.password_confirmation) {
			errorMessage = "As senhas não coincidem.";
			comErro = ["password_confirmation"];
			return;
		}

		isLoading = true;

		try {
			const response = await apiFetch("/users", {
				method: "POST",
				body: JSON.stringify({
					name: campos.name,
					cpf: campos.cpf.replace(/\D/g, ""),
					birthday: dataParaIso(campos.birthday),
					sex: campos.sex,
					marital_status_id: Number(campos.marital_status_id),
					email: campos.email,
					phone: campos.phone.replace(/\D/g, ""),
					password: campos.password,
					password_confirmation: campos.password_confirmation,
					photo: "https://example.com/foto.jpg",
				}),
			});

			const data = await response.json();

			if (response.ok && destino && data.access_token) {
				auth.setToken(data.access_token);
				auth.user = data.user;
				await auth.refreshUser();
				tempoRedirecionar = 1500;
				successMessage = "Voltando para a inscrição...";
				const voltarPara = destino;
				setTimeout(() => {
					goto(voltarPara);
				}, tempoRedirecionar);
			} else if (response.ok) {
				tempoRedirecionar = 2000;
				successMessage = "Redirecionando...";
				setTimeout(() => {
					goto("/login");
				}, tempoRedirecionar);
			} else {
				mostrarErroDaApi(data);
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

<AcessoLayout variante="cadastro">
	{#if successMessage}
		<div class="ac-sucesso" role="status">
			<div class="ac-sucesso-arco">
				<span class="ac-sucesso-check">✓</span>
			</div>
			<h1 class="ac-titulo">Conta criada com sucesso!</h1>
			<p>{successMessage}</p>
			<div class="ac-progresso">
				<span style="animation-duration: {tempoRedirecionar}ms"></span>
			</div>
		</div>
	{:else}
		<form class="ac-form" onsubmit={step === 2 ? handleSubmit : nextStep} novalidate>
			<div class="ac-etapas" aria-label="Etapa {step} de 2">
				<span class="ac-etapa-num" class:atual={step === 1} class:feita={step === 2}>
					{step === 2 ? "✓" : "1"}
				</span>
				<span class="ac-etapa-nome">Seus dados</span>
				<span class="ac-etapa-linha" class:feita={step === 2}></span>
				<span class="ac-etapa-num" class:atual={step === 2} class:pendente={step === 1}>2</span>
				<span class="ac-etapa-nome" class:pendente={step === 1}>Contato e senha</span>
			</div>

			<div class="ac-titulos">
				<span class="ac-sobretitulo">Etapa {step} de 2</span>
				<h1 class="ac-titulo">Criar conta</h1>
				<p class="ac-subtitulo">
					{#if step === 2}
						Quase lá! Agora só faltam seu contato e uma senha.
					{:else if veioDeInscricao}
						Crie sua conta para concluir a inscrição. Leva cerca de 2 minutos.
					{:else}
						Leva cerca de 2 minutos. Seus dados ajudam a equipe a cuidar de cada inscrição.
					{/if}
				</p>
			</div>

			{#if errorMessage}
				<div class="ac-erro" role="alert">
					<span class="ac-erro-icone">!</span>
					<span>{errorMessage}</span>
				</div>
			{/if}

			{#if step === 1}
				<div class="ac-grade">
					<div class="ac-campo ac-largo">
						<label for="name" class="ac-rotulo">Nome completo</label>
						<input
							id="name"
							class="ac-input"
							autocomplete="name"
							maxlength="255"
							placeholder="Como está no documento"
							value={campos.name}
							oninput={aoDigitar("name")}
							aria-invalid={temErro("name")}
						/>
					</div>

					<div class="ac-campo">
						<label for="cpf" class="ac-rotulo">CPF</label>
						<input
							id="cpf"
							class="ac-input"
							inputmode="numeric"
							placeholder="000.000.000-00"
							value={campos.cpf}
							oninput={aoDigitar("cpf")}
							aria-invalid={temErro("cpf")}
							aria-describedby={cpfJaCadastrado ? "cpf-msg" : undefined}
						/>
						{#if cpfJaCadastrado}
							<span id="cpf-msg" class="ac-msg-campo">
								Este CPF já tem uma conta. <a href={linkLogin}>Fazer login</a>
							</span>
						{/if}
					</div>

					<div class="ac-campo">
						<label for="birthday" class="ac-rotulo">Data de nascimento</label>
						<input
							id="birthday"
							class="ac-input"
							inputmode="numeric"
							autocomplete="bday"
							placeholder="dd/mm/aaaa"
							value={campos.birthday}
							oninput={aoDigitar("birthday")}
							aria-invalid={temErro("birthday")}
						/>
					</div>

					<div class="ac-campo">
						<span class="ac-rotulo" id="sexo-rotulo">Sexo</span>
						<div
							class="ac-opcoes"
							class:ac-com-erro={comErro.includes("sex")}
							role="radiogroup"
							aria-labelledby="sexo-rotulo"
						>
							{#each [{ valor: "M", rotulo: "Masculino" }, { valor: "F", rotulo: "Feminino" }] as opcao}
								<button
									type="button"
									class="ac-opcao"
									role="radio"
									aria-checked={campos.sex === opcao.valor}
									onclick={() => editar("sex", opcao.valor)}
								>
									{opcao.rotulo}
								</button>
							{/each}
						</div>
					</div>

					<div class="ac-campo">
						<label for="marital_status_id" class="ac-rotulo">Estado civil</label>
						<select
							id="marital_status_id"
							class="ac-input"
							class:ac-vazio={!campos.marital_status_id}
							value={campos.marital_status_id}
							onchange={aoDigitar("marital_status_id")}
							aria-invalid={temErro("marital_status_id")}
						>
							<option value="">Selecione</option>
							{#each maritalStatuses as status}
								<option value={String(status.id)}>{status.title}</option>
							{/each}
						</select>
					</div>
				</div>

				<button type="submit" class="ac-btn-principal">Próximo →</button>
			{:else}
				<div class="ac-grade">
					<div class="ac-campo">
						<label for="email" class="ac-rotulo">E-mail</label>
						<input
							id="email"
							type="email"
							class="ac-input"
							autocomplete="email"
							placeholder="nome@email.com"
							value={campos.email}
							oninput={aoDigitar("email")}
							aria-invalid={temErro("email")}
						/>
					</div>

					<div class="ac-campo">
						<label for="phone" class="ac-rotulo">Telefone (WhatsApp)</label>
						<input
							id="phone"
							class="ac-input"
							inputmode="tel"
							autocomplete="tel"
							placeholder="(00) 00000-0000"
							value={campos.phone}
							oninput={aoDigitar("phone")}
							aria-invalid={temErro("phone")}
						/>
					</div>

					<div class="ac-campo ac-largo">
						<label for="password" class="ac-rotulo">Senha</label>
						<div class="ac-senha">
							<input
								id="password"
								class="ac-input"
								type={mostrarSenha ? "text" : "password"}
								autocomplete="new-password"
								placeholder="Crie uma senha"
								value={campos.password}
								oninput={aoDigitar("password")}
								aria-invalid={temErro("password")}
							/>
							<button
								type="button"
								class="ac-mostrar"
								onclick={() => (mostrarSenha = !mostrarSenha)}
								aria-label={mostrarSenha ? "Ocultar senhas" : "Mostrar senhas"}
							>
								{mostrarSenha ? "Ocultar" : "Mostrar"}
							</button>
						</div>
					</div>

					<div class="ac-campo ac-largo">
						<label for="password_confirmation" class="ac-rotulo">Confirmar senha</label>
						<input
							id="password_confirmation"
							class="ac-input"
							type={mostrarSenha ? "text" : "password"}
							autocomplete="new-password"
							placeholder="Digite a senha novamente"
							value={campos.password_confirmation}
							oninput={aoDigitar("password_confirmation")}
							aria-invalid={temErro("password_confirmation")}
							aria-describedby={comErro.includes("password_confirmation") && campos.password_confirmation
								? "conf-msg"
								: undefined}
						/>
						{#if comErro.includes("password_confirmation") && campos.password_confirmation}
							<span id="conf-msg" class="ac-msg-campo">As senhas precisam ser iguais.</span>
						{/if}
					</div>
				</div>

				<div class="ac-botoes">
					<button type="button" class="ac-btn-secundario" onclick={prevStep} disabled={isLoading}>
						← Voltar
					</button>
					<button type="submit" class="ac-btn-principal" disabled={isLoading}>
						{#if isLoading}
							<span class="ac-spinner"></span>Enviando...
						{:else}
							Finalizar
						{/if}
					</button>
				</div>
			{/if}

			<p class="ac-rodape">
				Já tem uma conta? <a href={linkLogin}>Faça login</a>
			</p>
		</form>
	{/if}
</AcessoLayout>
