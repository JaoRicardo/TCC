<script lang="ts">
	/**
	 * Detalhes da atividade ("Ver Detalhes" no Início). Pública: o visitante vê
	 * tudo e só entra na conta na hora de se inscrever. O centro da tela é o
	 * bloco "Sua inscrição" (um por situação, com o próximo passo e o botão
	 * certo); depois vêm o cronograma em linha do tempo, os valores e os
	 * documentos. As regras de verdade (elegibilidade, prazos, vagas) são do
	 * servidor: aqui só se escolhe o que mostrar.
	 */
	import { page } from '$app/stores';
	import { tick, untrack } from 'svelte';
	import { apiFetch } from '$lib/api';
	import { auth } from '$lib/stores/auth.svelte';
	import { casal } from '$lib/stores/casal.svelte';
	import {
		getElegibilidade,
		getMinhasInscricoes,
		type Elegibilidade,
		type MinhaInscricao
	} from '$lib/services/inscricaoService';
	import { loginUrl, registroUrl } from '$lib/redirect';
	import CabecalhoSite from '$lib/components/CabecalhoSite.svelte';
	import SeloFase from '$lib/components/SeloFase.svelte';
	import { datasDaAtividade, faseDaAtividade, servosAbertosAte } from '$lib/faseAtividade';
	import { faixaNaFrase, textoDaFaixa } from '$lib/faixaEtaria';
	import logoAsas from '$lib/imgs/logo-asas.png';
	import { onMount } from 'svelte';

	type Tom = 'ok' | 'espera' | 'cancel' | 'pend' | 'cinza' | 'neutro';
	type Trilha = 'campista' | 'servo' | 'participante';

	interface Marco {
		k: string;
		t: string;
		em?: Date;
		de?: Date;
		ate?: Date;
		/** Evento sem fim das inscrições: valem até o evento começar. */
		semFim?: boolean;
		desc: string;
		/** O prazo da correção desta pessoa. */
		pessoal?: boolean;
	}

	interface Acao {
		label: string;
		ouro: boolean;
		href?: string;
		/** Abre numa aba nova (pagamento). */
		fora?: boolean;
		onclick?: () => void;
	}

	interface Opcao {
		trilha: Trilha;
		nome: string;
		desc: string;
		pode: boolean;
		prazo: string;
		botao: string;
		porque: string;
	}

	interface Bloco {
		tom: Tom;
		selo: string;
		titulo: string;
		texto: string;
		motivo: { txt: string; rodape: string } | null;
		passo: { rot: string; data: string; rel: string; aviso: string; alerta: boolean } | null;
		pagamento: boolean;
		acoes: Acao[];
		opcoes: Opcao[] | null;
		criarConta: boolean;
	}

	let activity: any = $state(null);
	let loading = $state(true);
	let error = $state('');
	let minhaInscricao = $state<MinhaInscricao | null>(null);
	let elegibilidade = $state<Record<string, Elegibilidade> | null>(null);
	/** Logado: ainda buscando a inscrição e a elegibilidade. */
	let carregandoPessoa = $state(false);
	let hoje = $state(new Date());

	const idAtividade = () => Number($page.params.id as string);

	onMount(() => {
		carregarAtividade();
		// A linha do tempo e o "agora" mudam com a hora: atualiza a cada minuto.
		const t = setInterval(() => (hoje = new Date()), 60_000);
		return () => clearInterval(t);
	});

	async function carregarAtividade() {
		try {
			const res = await apiFetch(`/activities/${$page.params.id}`, { redirectOnUnauthorized: false });
			const data = await res.json();
			if (res.ok) activity = data.data;
			else error = data.error || 'Não foi possível carregar os detalhes desta atividade.';
		} catch {
			error = 'Falha de comunicação com o servidor.';
		} finally {
			loading = false;
		}
	}

	async function carregarPessoa() {
		const id = idAtividade();
		const [{ inscricoes }, eleg] = await Promise.all([getMinhasInscricoes(), getElegibilidade(id)]);
		minhaInscricao = inscricoes.find((i) => i.activity?.id === id) ?? null;
		elegibilidade = eleg;
	}

	// A pessoa respondeu a janela do casal: a inscrição mudou (ou foi cancelada).
	$effect(() => {
		if (casal.respostas > 0 && untrack(() => pessoaCarregada)) carregarPessoa();
	});

	// Espera a sessão salva ser conferida: visitante não tem inscrição para buscar
	// (e levaria um 401). `?inscrever=1` chega do cartão do Início ou da volta do login.
	let pessoaCarregada = false;
	$effect(() => {
		if (!activity || auth.isLoading || !auth.isAuthenticated || pessoaCarregada) return;
		pessoaCarregada = true;
		carregandoPessoa = true;
		carregarPessoa()
			.then(() => {
				if ($page.url.searchParams.get('inscrever') !== '1' || minhaInscricao) return;
				const escolha = opcoes?.find((o) => o.pode);
				if (escolha) abrirJanela(escolha.trilha);
			})
			.catch((e) => console.error('Erro ao carregar a inscrição:', e))
			.finally(() => (carregandoPessoa = false));
	});

	// ─── Datas ────────────────────────────────────────────────────────────

	const MES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
	const MESL = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
	const p2 = (x: number) => String(x).padStart(2, '0');
	const dt = (s?: string | null) => (s ? new Date(s) : undefined);
	const fData = (x: Date) => `${p2(x.getDate())}/${p2(x.getMonth() + 1)}/${x.getFullYear()}`;
	const fCurta = (x: Date) => `${p2(x.getDate())}/${p2(x.getMonth() + 1)}`;
	// Evento e acampamento: as datas não têm hora (aberturas às 00h00; fechamentos
	// e prazos valem até 23h59 do dia). Por isso só a data aparece.
	const fDH = fData;
	/** "2026-11-18" (só a data) → Date local, sem o deslocamento do UTC. */
	const diaLocal = (s: string) => new Date(s.length <= 10 ? `${s}T00:00` : s);
	const diaMes = (x: Date) => `${x.getDate()} ${MES[x.getMonth()]}`;
	const dia0 = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
	const difDias = (a: Date, b: Date) => Math.round((dia0(b) - dia0(a)) / 864e5);
	const fimDoDia = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate(), 23, 59, 59);

	function quando(x: Date): string {
		const n = difDias(hoje, x);
		return n === 0 ? 'hoje' : n === 1 ? 'amanhã' : n > 1 ? `em ${n} dias` : '';
	}

	function daqui(x: Date): string {
		const q = quando(x);
		return q === 'hoje' ? 'É hoje' : q === 'amanhã' ? 'É amanhã' : q ? q.replace('em ', 'Daqui a ') : '';
	}

	/** "15 a 17 de janeiro de 2027" / "30 de janeiro a 2 de fevereiro de 2027". */
	function periodo(a: Date, b: Date): string {
		if (dia0(a) === dia0(b)) return `${a.getDate()} de ${MESL[a.getMonth()]} de ${a.getFullYear()}`;
		if (a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth())
			return `${a.getDate()} a ${b.getDate()} de ${MESL[b.getMonth()]} de ${b.getFullYear()}`;
		const anoA = a.getFullYear() === b.getFullYear() ? '' : ` de ${a.getFullYear()}`;
		return `${a.getDate()} de ${MESL[a.getMonth()]}${anoA} a ${b.getDate()} de ${MESL[b.getMonth()]} de ${b.getFullYear()}`;
	}

	const brl = (v?: number | string | null) =>
		v && Number(v) > 0 ? Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) : 'Gratuito';

	// O servidor guarda "#" quando o arquivo/link não foi enviado.
	const linkDeVerdade = (url?: string | null) => !!url && url !== '#' && !url.endsWith('/#');

	function tamanho(bytes?: number | null): string {
		if (!bytes) return '';
		return bytes < 1024 * 1024
			? `${Math.max(1, Math.round(bytes / 1024))} KB`
			: `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
	}

	// ─── A atividade ──────────────────────────────────────────────────────

	let ehEvento = $derived(activity?.tipo === 'Evento');
	let categoria = $derived<string>(activity?.categoria?.nome ?? '');
	let inicio = $derived(dt(activity?.inicioEvento));
	let fim = $derived(dt(activity?.fimEvento) ?? dt(activity?.inicioEvento));
	let datas = $derived.by(() => {
		if (!inicio) return 'A combinar';
		return periodo(inicio, fim ?? inicio);
	});
	let imagem = $derived<string | null>(activity?.imagem ?? null);

	const taxaDe = (t: Trilha) => Number((t === 'servo' ? activity?.taxaServo : activity?.taxaParticipante) ?? 0);
	const prazoPagDe = (t: Trilha) => dt(t === 'servo' ? activity?.prazoPagamentoServo : activity?.prazoPagamentoParticipante);

	/** Os marcos de cada trilha. Marco sem data cadastrada não entra. */
	function marcosDe(t: Trilha): Marco[] {
		if (!activity) return [];
		const a = activity;
		const lista: (Marco | null)[] = [];
		const taxa = taxaDe(t);
		const pag = prazoPagDe(t);
		const fimAtividade = fim ? fimDoDia(fim) : undefined;
		if (t === 'participante') {
			const ate = dt(a.fimInscricoes) ?? inicio;
			lista.push(
				ate
					? {
							k: 'insc',
							t: 'Inscrições',
							de: dt(a.inicioInscricoes),
							ate,
							semFim: !a.fimInscricoes,
							desc: 'Valem enquanto houver vagas. A vaga é de quem conclui a inscrição primeiro.'
						}
					: null,
				pag && taxa > 0 ? { k: 'pag', t: 'Prazo do pagamento', em: pag, desc: `${brl(taxa)}.` } : null,
				inicio ? { k: 'ativ', t: 'Evento', de: inicio, ate: fimAtividade, desc: `${a.local || ''}`.trim() } : null
			);
		} else {
			const servo = t === 'servo';
			const inscDe = dt(servo ? a.inicioInscricoesServos : a.inicioInscricoes);
			const inscAte = dt(servo ? a.fimInscricoesServos : a.fimInscricoes);
			const sorteio = dt(servo ? a.inicioSorteioServo : a.inicioSorteio);
			const form = dt(servo ? a.prazoFormularioServo : a.prazoFormularioCampista);
			lista.push(
				inscAte
					? {
							k: 'insc',
							t: servo ? 'Inscrições para servir' : 'Inscrições para o sorteio',
							de: inscDe,
							ate: inscAte,
							desc: servo
								? 'Para quem já foi campista nesta categoria ou numa acima dela.'
								: 'Quem se inscreve concorre às vagas de campista.'
						}
					: null,
				// O sorteio é feito à mão pela organização: a data é a prevista.
				sorteio
					? {
							k: 'sorteio',
							t: servo ? 'Sorteio de servos (data prevista)' : 'Sorteio (data prevista)',
							em: sorteio,
							desc: 'Feito pela organização. O resultado aparece aqui e no Início.'
						}
					: null,
				// Um prazo só por trilha: o do formulário é também o do pagamento.
				form
					? {
							k: 'form',
							t: taxa > 0 ? 'Prazo do formulário e do pagamento' : 'Prazo do formulário',
							em: form,
							desc:
								taxa > 0
									? `Para quem tem vaga: envie o formulário e pague ${brl(taxa)} direto com a organização. Quem perde o prazo perde a vaga.`
									: 'Para quem tem vaga: envie o formulário. Quem perde o prazo perde a vaga.'
						}
					: null,
				inicio ? { k: 'ativ', t: 'Acampamento', de: inicio, ate: fimAtividade, desc: `${a.local || ''}.` } : null
			);
		}
		return lista.filter((m): m is Marco => m !== null);
	}

	const inscricoesDe = (t: Trilha) => marcosDe(t).find((m) => m.k === 'insc');
	const abertaHoje = (m?: Marco) => !!m?.ate && (!m.de || m.de <= hoje) && hoje <= m.ate;

	let trilhasDaAtividade = $derived<Trilha[]>(ehEvento ? ['participante'] : ['campista', 'servo']);
	let algumaAberta = $derived(trilhasDaAtividade.some((t) => abertaHoje(inscricoesDe(t))));
	let nenhumaAbriu = $derived.by(() => {
		const insc = trilhasDaAtividade.map(inscricoesDe).filter((m): m is Marco => !!m);
		if (!insc.length) return !(fim && hoje > fimDoDia(fim));
		return insc.every((m) => !!m.de && hoje < m.de);
	});
	/** Selo do topo: a mesma fase do cartão do Início (`lib/faseAtividade.ts`). */
	let datasDaFase = $derived(activity ? datasDaAtividade(activity) : null);
	let fase = $derived(datasDaFase ? faseDaAtividade(datasDaFase, hoje) : 'emBreve');
	let servosAte = $derived(datasDaFase ? servosAbertosAte(datasDaFase, fase, hoje) : null);

	// ─── A pessoa ─────────────────────────────────────────────────────────

	let trilhaInscrita = $derived.by<Trilha | null>(() => {
		if (!minhaInscricao) return null;
		if (minhaInscricao.tipo === 'Servo') return 'servo';
		return ehEvento ? 'participante' : 'campista';
	});

	/** A trilha da linha do tempo: a da inscrição, ou a que a pessoa pode escolher. */
	let trilhaPrincipal = $derived.by<Trilha>(() => {
		if (ehEvento) return 'participante';
		if (trilhaInscrita) return trilhaInscrita;
		if (elegibilidade && !elegibilidade.campista?.pode && elegibilidade.servo?.pode) return 'servo';
		return 'campista';
	});

	/** A situação que escolhe o bloco "Sua inscrição". */
	let sit = $derived.by(() => {
		const m = minhaInscricao;
		if (!m) return algumaAberta ? 'abertas' : nenhumaAbriu ? 'emBreve' : 'encerradas';
		switch (m.status) {
			case 'Inscrito':
				return ehEvento ? 'evInscrito' : m.naoSorteado ? 'naoSorteado' : 'inscrito';
			case 'Sorteado':
				return 'sorteado';
			case 'Indicado':
				return 'indicado';
			case 'Formulário Enviado':
				return 'enviado';
			case 'Suplente':
				return 'suplente';
			case 'Correção pedida':
				return 'correcao';
			case 'Cancelada pela organização':
				return 'cancelada';
			case 'Recusada':
				return 'recusada';
			case 'Prazo Perdido':
				return m.perdeuPor === 'pagamento' ? 'prazoPag' : 'prazoForm';
			case 'Retirado do sorteio':
				return 'retirado';
			case 'Desistência':
				return 'desistencia';
			case 'Inscrição em andamento':
				return 'evAndamento';
			case 'Aguardando cônjuge':
				return m.casal?.euConfirmo ? 'casalConfirmar' : 'casalAguardando';
			case 'Casal não confirmado':
				return 'casalNaoConfirmado';
			default:
				return 'inscrito';
		}
	});

	let minhaTaxa = $derived(taxaDe(trilhaInscrita ?? trilhaPrincipal));
	let meuPrazoPag = $derived(prazoPagDe(trilhaInscrita ?? trilhaPrincipal));
	// Sem pagamento online: a pessoa paga direto com a organização, que registra.
	let gratis = $derived(minhaTaxa <= 0);
	let pago = $derived(!!minhaInscricao?.isPago);
	let pagoEm = $derived(minhaInscricao?.pagoEm ? fData(diaLocal(minhaInscricao.pagoEm)) : '');
	/** Suplente chamado para a vaga de quem pagou e saiu: não precisa pagar (o pagamento fica na vaga). */
	let vagaJaPaga = $derived(!!minhaInscricao?.pagamento?.herdado);
	let faltaPagar = $derived(!gratis && !pago);
	/** Já precisa pagar: formulário enviado (acampamento) ou inscrição concluída (evento). */
	let situacaoPaga = $derived(sit === 'enviado' || sit === 'evInscrito');

	const linkForm = (m: MinhaInscricao) => `/dashboard/formulario/${m.id}`;

	function porqueNaoPode(e: Elegibilidade | undefined, t: Trilha): string {
		if (!e) return '';
		if (e.codigo === 'ja_campista') return `Você já foi campista em ${categoria}: aqui só pode se inscrever como servo(a).`;
		if (e.codigo === 'nao_pode_servir' || e.codigo === 'nunca_campista')
			return `Você ainda não pode servir em ${categoria}: só é possível servir nas categorias em que já foi campista, ou abaixo delas.`;
		return e.mensagem ?? (t === 'servo' ? 'Você não pode se inscrever como servo(a) aqui.' : 'Você não pode se inscrever como campista aqui.');
	}

	/** Cartões de escolha (logado, sem inscrição). */
	let opcoes = $derived.by<Opcao[] | null>(() => {
		if (!activity || minhaInscricao || !auth.isAuthenticated) return null;
		const lista: Trilha[] = ehEvento ? ['participante'] : ['campista', 'servo'];
		return lista.map((t) => {
			const insc = inscricoesDe(t);
			const aberta = abertaHoje(insc);
			const e = elegibilidade?.[t];
			const elegivel = e ? e.pode : true;
			let porque = '';
			if (!elegivel) porque = porqueNaoPode(e, t);
			else if (!aberta) {
				const quem = t === 'servo' ? 'Inscrições para servir' : 'Inscrições para campistas';
				porque =
					insc?.de && insc.ate
						? `${quem}: de ${fCurta(insc.de)} a ${fCurta(insc.ate)}.`
						: insc?.ate
							? `${quem}: até ${fCurta(insc.ate)}.`
							: `${quem}: ainda sem data.`;
			}
			return {
				trilha: t,
				nome: t === 'servo' ? 'Servo(a)' : t === 'campista' ? 'Campista' : 'Participante',
				desc:
					t === 'servo'
						? 'Faz parte da equipe que prepara e cuida do acampamento.'
						: t === 'campista'
							? 'Participa do acampamento e concorre ao sorteio das vagas.'
							: 'Participa do evento.',
				pode: elegivel && aberta,
				prazo:
					t === 'participante'
						? activity.fimInscricoes
							? `Inscrições até ${fDH(new Date(activity.fimInscricoes))}, enquanto houver vagas.`
							: 'Inscrições até o evento começar, enquanto houver vagas.'
						: insc?.ate
							? `Inscrições até ${fDH(insc.ate)}.`
							: '',
				botao: `Inscrever-se como ${t === 'servo' ? 'Servo' : t === 'campista' ? 'Campista' : 'Participante'}`,
				porque
			};
		});
	});

	function passo(rot: string, data: Date | undefined, aviso = '', alerta = false) {
		return data ? { rot, data: fDH(data), rel: quando(data), aviso, alerta } : null;
	}

	let bloco = $derived.by<Bloco>(() => {
		const B: Bloco = {
			tom: 'neutro',
			selo: '',
			titulo: '',
			texto: '',
			motivo: null,
			passo: null,
			pagamento: false,
			acoes: [],
			opcoes: null,
			criarConta: false
		};
		if (!activity) return B;
		const m = minhaInscricao;
		const t = trilhaInscrita ?? trilhaPrincipal;
		const marcos = marcosDe(t);
		const get = (k: string) => marcos.find((x) => x.k === k);
		const sorteio = get('sorteio')?.em;
		const prazoForm = dt(m?.prazoFormulario) ?? get('form')?.em;
		const formAcao = (label: string, ouro = true): Acao[] => (m ? [{ label, ouro, href: linkForm(m) }] : []);
		const inscCampista = inscricoesDe(ehEvento ? 'participante' : 'campista');
		const inscServo = ehEvento ? undefined : inscricoesDe('servo');
		// Acampamento: um prazo só por trilha, para o formulário e o pagamento.
		const rotuloPrazo = !ehEvento && taxaDe(t) > 0 ? 'Prazo do formulário e do pagamento' : 'Prazo do formulário';

		switch (sit) {
			case 'abertas': {
				if (!auth.isAuthenticated) {
					const ate = trilhasDaAtividade.map(inscricoesDe).find((x) => abertaHoje(x))?.ate;
					B.titulo = !ate ? 'As inscrições estão abertas.' : `As inscrições estão abertas até ${fDH(ate)}.`;
					B.texto = 'Para se inscrever, entre na sua conta ou crie uma. Depois de entrar, você volta direto para esta página.';
					B.acoes = [{ label: 'Entrar para se inscrever', ouro: true, href: loginUrl(`/dashboard/atividades/${activity.id}?inscrever=1`) }];
					B.criarConta = true;
					break;
				}
				// Evento com faixa etária e a pessoa fora dela: aviso antes de tentar, sem botão.
				const foraDaFaixa = elegibilidade?.participante?.codigo;
				if (ehEvento && (foraDaFaixa === 'fora_faixa_etaria' || foraDaFaixa === 'sem_nascimento')) {
					if (foraDaFaixa === 'sem_nascimento') {
						B.titulo = 'Complete a data de nascimento para se inscrever.';
						B.texto = elegibilidade?.participante?.mensagem ?? '';
					} else {
						B.titulo = `Este evento é para pessoas ${faixaNaFrase(activity.idadeMinima, activity.idadeMaxima)}.`;
						B.texto = 'Pela data de nascimento do seu cadastro, você não pode se inscrever. A idade é contada no primeiro dia do evento.';
					}
					break;
				}
				B.titulo = ehEvento ? 'As inscrições estão abertas.' : 'Escolha como quer participar.';
				B.texto = !ehEvento
					? ''
					: activity.formularioId
						? 'A vaga é de quem conclui a inscrição primeiro: depois de se inscrever, responda o formulário.'
						: 'A vaga é de quem se inscreve primeiro, enquanto houver vagas.';
				B.opcoes = opcoes;
				break;
			}
			case 'emBreve':
				if (ehEvento) {
					B.titulo = inscCampista?.de ? `As inscrições abrem em ${fDH(inscCampista.de)}.` : 'As inscrições ainda não abriram.';
					B.texto = 'Volte nessa data para se inscrever.';
				} else {
					B.titulo = 'As inscrições ainda não abriram.';
					const partes = [
						inscCampista?.de ? `Para campistas, abrem em ${fDH(inscCampista.de)}.` : '',
						inscServo?.de ? `Para servos, em ${fDH(inscServo.de)}.` : ''
					].filter(Boolean);
					B.texto = partes.length ? `${partes.join(' ')} Volte nessas datas para se inscrever.` : 'As datas das inscrições ainda não foram divulgadas.';
				}
				break;
			case 'encerradas':
				B.titulo = 'As inscrições terminaram.';
				if (ehEvento) B.texto = inicio && hoje >= inicio ? 'O evento já começou.' : inscCampista?.ate ? `Terminaram em ${fDH(inscCampista.ate)}.` : '';
				else
					B.texto = [
						inscCampista?.ate ? `Para campistas, terminaram em ${fDH(inscCampista.ate)}.` : '',
						inscServo?.ate ? `Para servos, em ${fDH(inscServo.ate)}.` : '',
						fase === 'aguardandoSorteio' && datasDaFase?.sorteio
							? `O sorteio de campistas está previsto para ${fDH(datasDaFase.sorteio)}.`
							: ''
					]
						.filter(Boolean)
						.join(' ');
				break;
			case 'inscrito':
				Object.assign(B, {
					tom: 'pend',
					selo: 'Inscrito(a)',
					titulo: 'Inscrição feita. Agora é aguardar o sorteio.',
					texto: `Você concorre a uma das vagas de ${t === 'servo' ? 'servo' : 'campista'}. O resultado aparece aqui e no Início.`,
					passo: passo(t === 'servo' ? 'Sorteio de servos (previsto)' : 'Sorteio (previsto)', sorteio)
				});
				break;
			case 'casalAguardando':
				Object.assign(B, {
					tom: 'espera',
					selo: 'Aguardando cônjuge',
					titulo: `Falta ${m?.casal?.conjuge ?? 'seu cônjuge'} confirmar a inscrição do casal.`,
					texto: 'Quando entrar no site, aparece um aviso para confirmar. Enquanto não confirmar, vocês ainda não concorrem ao sorteio.',
					passo: passo('Sorteio (previsto)', sorteio, 'Sem a confirmação até o sorteio, o casal fica de fora.', true)
				});
				break;
			case 'casalConfirmar':
				Object.assign(B, {
					tom: 'espera',
					selo: 'Falta você confirmar',
					titulo: `${m?.casal?.conjuge ?? 'Seu cônjuge'} inscreveu vocês dois como casal.`,
					texto: 'Confirme que está ciente para a inscrição valer. Sem isso, o casal não concorre ao sorteio.',
					// A janela do casal (JanelaCasal) fica aberta por cima até a pessoa responder.
					passo: passo('Sorteio (previsto)', sorteio, 'Dá para confirmar até a organização fazer o sorteio.', true)
				});
				break;
			case 'casalNaoConfirmado':
				Object.assign(B, {
					tom: 'cinza',
					selo: 'Casal não confirmado',
					titulo: 'A inscrição do casal não foi confirmada antes do sorteio.',
					texto: 'Por isso, vocês não concorreram às vagas.',
					acoes: [{ label: 'Ver outros acampamentos e eventos', ouro: false, href: '/dashboard' }]
				});
				break;
			case 'naoSorteado':
				Object.assign(B, {
					tom: 'cinza',
					selo: 'Não sorteado(a)',
					titulo: 'Desta vez você não foi sorteado(a).',
					texto: 'O sorteio já foi feito pela organização.',
					acoes: [{ label: 'Ver outros acampamentos e eventos', ouro: false, href: '/dashboard' }]
				});
				break;
			case 'sorteado':
			case 'indicado':
				Object.assign(B, {
					tom: 'ok',
					selo: sit === 'sorteado' ? 'Sorteado(a)' : 'Indicado(a)',
					titulo:
						sit === 'sorteado'
							? 'Você foi sorteado(a)! Falta responder o formulário.'
							: 'Você foi indicado(a) pela organização! Falta responder o formulário.',
					texto: m?.temFormulario
						? 'Responda as perguntas da organização para garantir a vaga.'
						: 'A organização ainda está preparando o formulário. Ele aparece aqui assim que estiver pronto.',
					passo: passo(rotuloPrazo, prazoForm, 'Quem não responde até o prazo perde a vaga.', true),
					acoes: m?.temFormulario ? formAcao('Responder formulário') : []
				});
				break;
			case 'enviado':
				Object.assign(B, {
					tom: 'ok',
					selo: 'Formulário enviado',
					titulo: faltaPagar ? 'Formulário enviado. Falta o pagamento.' : 'Tudo certo! Sua vaga está garantida.',
					texto: prazoForm
						? hoje <= prazoForm
							? `Você pode alterar as respostas até ${fDH(prazoForm)}.`
							: `O prazo para alterar as respostas terminou em ${fDH(prazoForm)}.`
						: '',
					pagamento: true,
					acoes: formAcao(prazoForm && hoje > prazoForm ? 'Ver formulário' : 'Ver ou editar formulário', false)
				});
				break;
			case 'suplente':
				Object.assign(B, {
					tom: 'espera',
					selo: 'Suplente',
					titulo: 'Você está na lista de suplentes.',
					texto:
						'Se alguém desistir ou perder o prazo, você pode ser chamado(a) para a vaga. Aí o formulário abre para você, com pelo menos 3 dias para responder e pagar. Enquanto estiver na fila, não precisa fazer nada.',
					acoes: []
				});
				break;
			case 'correcao':
				Object.assign(B, {
					tom: 'espera',
					selo: 'Correção pedida',
					titulo: 'A organização pediu uma correção no seu formulário.',
					texto: 'Você pode mudar qualquer resposta. Depois, envie o formulário de novo.',
					motivo: m?.motivoOrganizacao
						? { txt: m.motivoOrganizacao, rodape: m.motivoEm ? `Pedido em ${fData(new Date(m.motivoEm))}` : '' }
						: null,
					passo: passo(
						'Prazo da correção',
						dt(m?.prazoCorrecao),
						'Se não corrigir até lá, a inscrição é recusada e a vaga vai para outra pessoa.',
						true
					),
					acoes: formAcao('Corrigir formulário')
				});
				break;
			case 'cancelada':
				Object.assign(B, {
					tom: 'cancel',
					selo: 'Cancelada pela organização',
					titulo: 'Sua inscrição foi cancelada pela organização.',
					motivo: m?.motivoOrganizacao
						? { txt: m.motivoOrganizacao, rodape: m.motivoEm ? `Cancelada em ${fData(new Date(m.motivoEm))}` : '' }
						: null
				});
				break;
			case 'recusada':
				Object.assign(B, {
					tom: 'cancel',
					selo: 'Recusada',
					titulo: 'Sua inscrição foi recusada.',
					texto: `${m?.motivoOrganizacao || 'Correção não feita no prazo.'} A vaga foi para outra pessoa.`
				});
				break;
			case 'prazoForm':
				Object.assign(B, {
					tom: 'cancel',
					selo: 'Prazo perdido',
					titulo: 'O prazo terminou e a vaga foi para outra pessoa.',
					texto: prazoForm ? `O formulário não foi respondido até ${fDH(prazoForm)}.` : 'O formulário não foi respondido no prazo.'
				});
				break;
			case 'prazoPag':
				Object.assign(B, {
					tom: 'cancel',
					selo: 'Prazo perdido',
					titulo: 'O prazo terminou e a vaga foi para outra pessoa.',
					texto: meuPrazoPag ? `O pagamento não foi feito até ${fDH(meuPrazoPag)}.` : 'O pagamento não foi feito no prazo.'
				});
				break;
			case 'retirado':
				Object.assign(B, {
					tom: 'cancel',
					selo: 'Retirado do sorteio',
					titulo: 'Você não concorre mais a este sorteio.',
					texto:
						'A organização corrigiu dados do seu cadastro (data de nascimento, sexo ou cidade). Com eles, você não entra nas regras deste acampamento.'
				});
				break;
			case 'desistencia':
				Object.assign(B, {
					tom: 'cancel',
					selo: 'Desistência',
					titulo: 'Você desistiu desta inscrição.',
					texto: 'A vaga foi liberada para outra pessoa.'
				});
				break;
			case 'evAndamento':
				Object.assign(B, {
					tom: 'espera',
					selo: 'Inscrição em andamento',
					titulo: 'Falta responder o formulário para concluir a inscrição.',
					texto: 'A vaga só fica garantida quando você enviar. Até lá, outras pessoas podem ocupar as vagas.',
					acoes: formAcao('Responder e concluir a inscrição')
				});
				break;
			case 'evInscrito':
				Object.assign(B, {
					tom: 'ok',
					selo: 'Inscrito(a)',
					titulo: faltaPagar ? 'Inscrição concluída. Falta o pagamento.' : 'Inscrição concluída. Sua vaga está garantida.',
					texto:
						m?.temFormulario && prazoForm && hoje <= prazoForm ? `Você pode alterar as respostas até ${fDH(prazoForm)}.` : '',
					pagamento: true,
					acoes: m?.temFormulario ? formAcao('Ver formulário', false) : []
				});
				break;
		}
		// Pagou adiantado (sorteado, suplente, em andamento...): a caixa mostra "Pago em".
		if (pago && !gratis && !['cancel', 'cinza'].includes(B.tom)) B.pagamento = true;
		return B;
	});

	let comoTexto = $derived(
		trilhaInscrita === 'servo' ? ' · Servo(a)' : trilhaInscrita === 'participante' ? ' · Participante' : trilhaInscrita ? ' · Campista' : ''
	);

	/** Caixa de pagamento dentro do bloco (formulário enviado / evento inscrito). */
	let caixaPagamento = $derived.by(() => {
		if (pago)
			return {
				valor: brl(minhaTaxa),
				linha: vagaJaPaga
					? 'Você entrou numa vaga que já estava paga: não precisa pagar.'
					: pagoEm
						? `Pago em ${pagoEm}.`
						: 'Pagamento confirmado.',
				selo: 'Pago',
				tom: 'ok'
			};
		if (gratis) return { valor: 'Gratuito', linha: 'Não há pagamento.', selo: 'Sem pagamento', tom: 'ok' };
		return {
			valor: brl(minhaTaxa),
			linha: meuPrazoPag
				? `Pague ${brl(minhaTaxa)} diretamente com a organização (dinheiro ou Pix) até ${fDH(meuPrazoPag)}. Quem não paga no prazo perde a vaga.`
				: `Pague ${brl(minhaTaxa)} diretamente com a organização (dinheiro ou Pix). Quem não paga perde a vaga.`,
			selo: 'Não pago',
			tom: 'espera'
		};
	});

	// ─── Cronograma ───────────────────────────────────────────────────────

	let fechado = $derived(bloco.tom === 'cancel' || bloco.tom === 'cinza');
	let servoAberto = $state(false);

	let cronograma = $derived.by(() => {
		const itens = marcosDe(trilhaPrincipal);
		if (sit === 'correcao' && minhaInscricao?.prazoCorrecao) {
			const i = itens.findIndex((x) => x.k === 'form');
			itens.splice(i + 1, 0, {
				k: 'corr',
				t: 'Prazo da sua correção',
				em: new Date(minhaInscricao.prazoCorrecao),
				desc: minhaInscricao.motivoEm ? `Pedido pela organização em ${fData(new Date(minhaInscricao.motivoEm))}.` : 'Pedido pela organização.',
				pessoal: true
			});
		}
		const fimDe = (x: Marco) => (x.em ?? x.ate) as Date;
		const iniDe = (x: Marco) => x.em ?? x.de;
		const estado = (x: Marco) => (fimDe(x) < hoje ? 'pass' : !iniDe(x) || (iniDe(x) as Date) <= hoje ? 'agora' : 'fut');
		const temAgora = itens.some((y) => estado(y) === 'agora' && y.k !== 'ativ');
		const idxProx = fechado || temAgora ? -1 : itens.findIndex((x) => estado(x) === 'fut');
		return itens.map((x, i) => {
			const e = estado(x);
			const prox = i === idxProx;
			const ref = (x.em ?? x.de ?? x.ate) as Date;
			const dia = x.em
				? diaMes(x.em)
				: x.semFim
					? x.de
						? `desde ${diaMes(x.de)}`
						: `até ${diaMes(x.ate as Date)}`
					: x.de && x.ate && dia0(x.de) !== dia0(x.ate)
						? `${diaMes(x.de)} – ${diaMes(x.ate)}`
						: diaMes((x.de ?? x.ate) as Date);
			const sub = `${((x.em ?? x.ate ?? x.de) as Date).getFullYear()}`;
			let desc = x.desc;
			if (!x.em) {
				if (x.k === 'ativ' && x.de && x.ate) desc = `${periodo(x.de, x.ate)}. ${x.desc}`.trim();
				else if (x.semFim) desc = `${x.de ? `De ${fDH(x.de)} até` : 'Até'} o evento começar. ${x.desc}`;
				else if (x.de && x.ate) desc = `De ${fDH(x.de)} até ${fDH(x.ate)}. ${x.desc}`;
				else if (x.ate) desc = `Até ${fDH(x.ate)}. ${x.desc}`;
			}
			const tag = x.pessoal ? 'Sua correção' : e === 'agora' ? 'Agora' : prox ? 'Próximo' : '';
			const rel =
				e === 'agora' && x.ate
					? difDias(hoje, x.ate) <= 0
						? 'Termina hoje'
						: `Termina ${quando(x.ate)}`
					: prox || (x.pessoal && e === 'fut')
						? daqui(ref)
						: '';
			const destaque = e === 'agora' || prox || (!!x.pessoal && e !== 'pass');
			return {
				...x,
				estado: e,
				prox,
				dia,
				sub,
				desc,
				tag,
				rel,
				destaque,
				atual: e === 'agora' || (prox && !temAgora),
				ultimo: i === itens.length - 1
			};
		});
	});

	let outraTrilha = $derived<Trilha | null>(ehEvento ? null : trilhaPrincipal === 'campista' ? 'servo' : 'campista');
	let outraLista = $derived.by(() => {
		if (!outraTrilha) return [];
		return marcosDe(outraTrilha)
			.filter((x) => x.k !== 'ativ')
			.map((x) => ({
				t: x.t,
				quando: x.em ? fDH(x.em) : x.de && x.ate ? `${fCurta(x.de)} a ${fCurta(x.ate)}` : x.ate ? `até ${fCurta(x.ate)}` : '',
				passou: ((x.em ?? x.ate) as Date) < hoje
			}));
	});
	let resumoOutra = $derived.by(() => {
		if (!outraTrilha) return '';
		const insc = inscricoesDe(outraTrilha);
		const quem = outraTrilha === 'servo' ? 'Inscrições para servir' : 'Inscrições para campistas';
		if (insc?.de && insc.ate) return `${quem}: de ${fCurta(insc.de)} a ${fCurta(insc.ate)}.`;
		if (insc?.ate) return `${quem}: até ${fCurta(insc.ate)}.`;
		return `${quem}: ainda sem data.`;
	});

	// ─── Valores e documentos ─────────────────────────────────────────────

	let linhasValores = $derived.by(() => {
		const lista: { quem: string; valor: string; voce: boolean }[] = [];
		const trilhas: Trilha[] = ehEvento ? ['participante'] : ['campista', 'servo'];
		for (const t of trilhas) {
			lista.push({
				quem: t === 'servo' ? 'Servo(a)' : t === 'campista' ? 'Campista' : 'Participante',
				valor: brl(taxaDe(t)),
				voce: trilhaInscrita === t
			});
		}
		return lista;
	});

	/**
	 * "Pagamento até …". Inscrito: o prazo da própria inscrição. Acampamento sem
	 * inscrição: o de cada trilha paga — "20/11/2026 (campistas) e 15/11/2026 (servos)".
	 */
	let prazoDosValores = $derived.by(() => {
		if (minhaInscricao || ehEvento) return !gratis && meuPrazoPag ? fDH(meuPrazoPag) : '';
		const partes = (['campista', 'servo'] as Trilha[])
			.filter((t) => taxaDe(t) > 0 && prazoPagDe(t))
			.map((t) => `${fDH(prazoPagDe(t) as Date)} (${t === 'servo' ? 'servos' : 'campistas'})`);
		return partes.join(' e ');
	});

	/** Vagas do sorteio de campistas (as indicações ficam fora e não têm limite). */
	let textoDasVagas = $derived.by(() => {
		if (!activity) return '';
		if (ehEvento) return activity.vagasTotais ? String(activity.vagasTotais) : '';
		const h = Number(activity.vagasSorteioHomem ?? 0);
		const m = Number(activity.vagasSorteioMulher ?? 0);
		const c = Number(activity.vagasSorteioCasal ?? 0);
		const total = h + m + 2 * c;
		if (!total) return '';
		const n = (x: number, um: string, varios: string) => `${x} ${x === 1 ? um : varios}`;
		const grupos = c ? [n(c, 'casal', 'casais')] : [h ? n(h, 'homem', 'homens') : '', m ? n(m, 'mulher', 'mulheres') : ''].filter(Boolean);
		return `${total} no sorteio de campistas (${grupos.join(' e ')})`;
	});

	let notaValores = $derived.by(() => {
		if (gratis) return 'Não há pagamento.';
		if (pago) return '';
		const direto = 'O pagamento é feito diretamente com a organização, em dinheiro ou Pix.';
		if (!minhaInscricao) return sit === 'encerradas' ? '' : direto;
		if (sit === 'prazoPag' && meuPrazoPag) return `O prazo de pagamento terminou em ${fDH(meuPrazoPag)}.`;
		if (fechado) return 'Esta inscrição não tem pagamento.';
		if (situacaoPaga) return 'Pague diretamente com a organização, em dinheiro ou Pix.';
		return `${direto} O prazo vale depois que você ${ehEvento ? 'concluir a inscrição' : 'enviar o formulário'}.`;
	});

	let documentos = $derived.by(() => {
		if (!activity || ehEvento) return [];
		const lista: { nome: string; url: string; tipo: string; info: string }[] = [];
		const add = (nome: string, url?: string | null, bytes?: number | null) => {
			if (!url || !linkDeVerdade(url)) return;
			const ext = (url.split('?')[0].split('.').pop() ?? '').toUpperCase();
			const tipo = ext === 'PDF' ? 'PDF' : ext.length <= 4 ? ext : 'ARQ';
			lista.push({ nome, url, tipo, info: [tipo === 'PDF' ? 'PDF' : 'Imagem', tamanho(bytes)].filter(Boolean).join(' · ') });
		};
		add('Edital do acampamento', activity.editalEvento, activity.editalTamanho);
		add('Termo de autorização', activity.termoAutorizacao, activity.termoTamanho);
		return lista;
	});

	// ─── Janela "Confirmar inscrição" ────────────────────────────────────

	let janelaAberta = $state(false);
	let janelaTrilha = $state<Trilha>('campista');
	let processando = $state(false);
	let erroJanela = $state('');
	let cpfConjuge = $state('');
	let end = $state({ cep: '', cidade: '' });
	let janelaEl: HTMLDivElement | undefined = $state();
	let quemAbriu: HTMLElement | null = null;
	let toast = $state('');
	let toastTimer: ReturnType<typeof setTimeout> | undefined;

	// Categoria só de casais (regra da categoria, tela Gestão › Categorias) ou evento para casais.
	let ehCasais = $derived(!!activity?.categoria?.soCasais || activity?.targetAudience === 'Casais');
	let casado = $derived(['Casado', 'União estável'].includes(auth.user?.marital_status?.title ?? ''));
	let pedeConjuge = $derived((ehCasais && janelaTrilha !== 'servo') || casado);
	let pedeEndereco = $derived(!auth.user?.address);
	let cpfOk = $derived(cpfConjuge.replace(/\D/g, '').length === 11);
	let enderecoOk = $derived(Object.values(end).every((v) => v.trim() !== ''));
	let invalido = $derived((pedeConjuge && !cpfOk) || (pedeEndereco && !enderecoOk));
	let erroNoCpf = $derived(!!erroJanela && pedeConjuge && /cpf|cônjuge/i.test(erroJanela));

	let fraseJanela = $derived.by(() => {
		if (ehEvento)
			return activity?.formularioId
				? 'Depois de confirmar, responda o formulário: a vaga só fica garantida quando você enviar.'
				: 'A vaga é sua assim que você confirmar, enquanto houver vagas.';
		const sorteio = marcosDe(janelaTrilha).find((x) => x.k === 'sorteio')?.em;
		if (janelaTrilha === 'servo')
			return sorteio
				? `O sorteio de servos está previsto para ${fDH(sorteio)}.`
				: 'Se houver mais inscritos do que vagas, haverá sorteio de servos.';
		return sorteio ? `Você vai concorrer ao sorteio previsto para ${fDH(sorteio)}.` : 'Você vai concorrer ao sorteio das vagas.';
	});

	function mascaraCpf(s: string) {
		const d = s.replace(/\D/g, '').slice(0, 11);
		return d
			.replace(/^(\d{3})(\d)/, '$1.$2')
			.replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
			.replace(/\.(\d{3})(\d)/, '.$1-$2');
	}

	async function abrirJanela(t: Trilha) {
		quemAbriu = document.activeElement as HTMLElement | null;
		janelaTrilha = t;
		cpfConjuge = '';
		end = { cep: '', cidade: '' };
		erroJanela = '';
		processando = false;
		janelaAberta = true;
		await tick();
		const primeiro = janelaEl?.querySelector<HTMLElement>('input') ?? janelaEl?.querySelector<HTMLElement>('.js-confirmar');
		primeiro?.focus();
	}

	function fecharJanela() {
		if (processando) return;
		janelaAberta = false;
		quemAbriu?.focus();
	}

	function teclaNaJanela(e: KeyboardEvent) {
		if (e.key === 'Escape') {
			e.preventDefault();
			fecharJanela();
			return;
		}
		if (e.key !== 'Tab' || !janelaEl) return;
		const focaveis = [...janelaEl.querySelectorAll<HTMLElement>('input, button:not([disabled]), a[href]')];
		if (!focaveis.length) return;
		const primeiro = focaveis[0];
		const ultimo = focaveis[focaveis.length - 1];
		if (e.shiftKey && document.activeElement === primeiro) {
			e.preventDefault();
			ultimo.focus();
		} else if (!e.shiftKey && document.activeElement === ultimo) {
			e.preventDefault();
			primeiro.focus();
		}
	}

	function avisar(texto: string) {
		toast = texto;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => (toast = ''), 7000);
	}

	async function confirmar(e: Event) {
		e.preventDefault();
		if (invalido || processando) return;
		processando = true;
		erroJanela = '';
		try {
			const body = new FormData();
			body.append('tipo', janelaTrilha === 'servo' ? 'Servo' : janelaTrilha === 'participante' ? 'Participante' : 'Campista');
			if (pedeConjuge) body.append('spouse_cpf', cpfConjuge.replace(/\D/g, ''));
			if (pedeEndereco) {
				body.append('city', end.cidade);
				body.append('cep', end.cep.replace(/\D/g, ''));
			}
			const res = await apiFetch(`/activities/${activity.id}/inscricoes`, { method: 'POST', body });
			const data = await res.json();
			if (!res.ok) {
				erroJanela = data.error || data.message || 'Tente de novo em instantes.';
				return;
			}
			processando = false;
			janelaAberta = false;
			await carregarPessoa();
			// O endereço novo fica no cadastro: a próxima inscrição não pede de novo.
			if (pedeEndereco) auth.refreshUser();
			avisar(
				data.data?.status === 'Inscrição em andamento'
					? 'Inscrição iniciada. Falta responder o formulário.'
					: data.data?.status === 'Aguardando cônjuge'
						? 'Inscrição feita! Agora falta seu cônjuge confirmar.'
						: ehEvento
						? 'Inscrição feita!'
						: 'Inscrição feita! Agora é aguardar o sorteio.'
			);
		} catch {
			erroJanela = 'Falha de comunicação com o servidor. Verifique a internet e tente de novo.';
		} finally {
			processando = false;
		}
	}
</script>

<svelte:head>
	<title>{activity ? activity.nome : 'Carregando…'} | Acampamento São Miguel</title>
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link
		href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap"
		rel="stylesheet"
	/>
</svelte:head>

<div class="pagina">
	<CabecalhoSite />

	<main class="conteudo">
		<a href="/dashboard" class="voltar">← Voltar para acampamentos e eventos</a>

		{#if loading}
			<p class="estado" role="status">Carregando informações…</p>
		{:else if error || !activity}
			<div class="card estado-erro">
				<p>{error || 'Atividade não encontrada.'}</p>
				<a href="/dashboard" class="btn b-azul">Voltar para o Início</a>
			</div>
		{:else}
			<!-- ===== Topo ===== -->
			<section class="topo" aria-labelledby="t-nome">
				<div class="topo-chumbo" aria-hidden="true"></div>
				<div class="topo-grid">
					<div class="topo-textos">
						<div class="selos">
							{#if ehEvento}
								<span class="sel sel-evento">Evento</span>
							{:else}
								<span class="sel sel-acamp">Acampamento</span>
								{#if categoria}<span class="sel sel-cat">{categoria}</span>{/if}
							{/if}
							<SeloFase {fase} />
						</div>
						<h1 id="t-nome">{activity.nome}</h1>
						{#if servosAte}
							<p class="topo-servos">
								<span class="losango-servos" aria-hidden="true"></span>
								<span>Inscrições para servos abertas até <strong>{fCurta(servosAte)}</strong></span>
							</p>
						{/if}
						<dl class="topo-dados">
							<div><dt>Local</dt><dd>{activity.local || 'A definir'}</dd></div>
							<div><dt>Datas</dt><dd>{datas}</dd></div>
							{#if ehEvento && textoDaFaixa(activity.idadeMinima, activity.idadeMaxima)}
								<div><dt>Idade</dt><dd>{textoDaFaixa(activity.idadeMinima, activity.idadeMaxima)}</dd></div>
							{/if}
							{#if textoDasVagas}
								<div><dt>Vagas</dt><dd>{textoDasVagas}</dd></div>
							{/if}
						</dl>
					</div>
					<div class="arco">
						{#if imagem}
							<img src={imagem} alt="" class="arco-foto" />
						{:else}
							<div class="arco-vitral"><img src={logoAsas} alt="" /></div>
						{/if}
						<div class="arco-moldura" aria-hidden="true"></div>
					</div>
				</div>
			</section>

			<div class="corpo">
				<div class="coluna">
					<!-- ===== Sua inscrição ===== -->
					<section class="card insc" aria-labelledby="t-insc">
						<div class="faixa faixa-{bloco.tom}"></div>
						<div class="insc-corpo">
							{#if carregandoPessoa || (auth.isLoading && !minhaInscricao)}
								<p class="ajuda" role="status">Carregando a sua inscrição…</p>
							{:else}
								<div class="insc-cab">
									<span class="rot">Sua inscrição{comoTexto}</span>
									{#if bloco.selo}<span class="pill t-{bloco.tom}"><i></i>{bloco.selo}</span>{/if}
								</div>
								<div class="insc-textos">
									<h2 id="t-insc">{bloco.titulo}</h2>
									{#if bloco.texto}<p>{bloco.texto}</p>{/if}
								</div>

								{#if bloco.motivo}
									<figure class="motivo">
										<span class="rot rot-cinza">Motivo</span>
										<blockquote>“{bloco.motivo.txt}”</blockquote>
										{#if bloco.motivo.rodape}<figcaption>{bloco.motivo.rodape}</figcaption>{/if}
									</figure>
								{/if}

								{#if bloco.passo}
									<div class="passo" class:passo--alerta={bloco.passo.alerta}>
										<div class="passo-textos">
											<span class="rot passo-rot">{bloco.passo.rot}</span>
											<span class="passo-data">{bloco.passo.data}</span>
											{#if bloco.passo.aviso}<span class="passo-aviso">{bloco.passo.aviso}</span>{/if}
										</div>
										{#if bloco.passo.rel}<span class="passo-rel">{bloco.passo.rel}</span>{/if}
									</div>
								{/if}

								{#if bloco.pagamento}
									<div class="pagamento">
										<div class="pagamento-textos">
											<span class="rot">Pagamento</span>
											<span class="pagamento-valor">{caixaPagamento.valor}</span>
											<span class="pagamento-linha">{caixaPagamento.linha}</span>
										</div>
										<span class="pill t-{caixaPagamento.tom}"><i></i>{caixaPagamento.selo}</span>
									</div>
								{/if}

								{#if bloco.opcoes}
									<div class="opcoes" style="grid-template-columns: repeat({bloco.opcoes.length}, minmax(0, 1fr));">
										{#each bloco.opcoes as o (o.trilha)}
											<div class="opcao" class:opcao--nao={!o.pode}>
												<span class="opcao-nome">{o.nome}</span>
												<span class="opcao-desc">{o.desc}</span>
												{#if o.pode}
													{#if o.prazo}<span class="ajuda">{o.prazo}</span>{/if}
													<button type="button" class="btn b-ouro" onclick={() => abrirJanela(o.trilha)}>{o.botao}</button>
												{:else}
													<span class="opcao-porque"><span aria-hidden="true"></span>{o.porque}</span>
												{/if}
											</div>
										{/each}
									</div>
								{/if}

								{#if bloco.acoes.length || bloco.criarConta}
									<div class="acoes">
										{#each bloco.acoes as a (a.label)}
											{#if a.onclick}
												<button type="button" class="btn" class:b-ouro={a.ouro} class:b-azul={!a.ouro} onclick={a.onclick}>{a.label}</button>
											{:else}
												<a
													href={a.href}
													class="btn"
													class:b-ouro={a.ouro}
													class:b-azul={!a.ouro}
													target={a.fora ? '_blank' : undefined}
													rel={a.fora ? 'noopener noreferrer' : undefined}>{a.label}</a
												>
											{/if}
										{/each}
										{#if bloco.criarConta}
											<span class="criar-conta"
												>Ainda não tem conta? <a href={registroUrl(`/dashboard/atividades/${activity.id}?inscrever=1`)}>Criar conta</a></span
											>
										{/if}
									</div>
								{/if}
							{/if}
						</div>
					</section>

					<!-- ===== Sobre o evento / acampamento (texto livre da organização) ===== -->
					{#if activity.sobre}
						<section class="card sobre" aria-labelledby="t-sobre">
							<h2 id="t-sobre" class="h2">{ehEvento ? 'Sobre o evento' : 'Sobre o acampamento'}</h2>
							<p class="sobre-texto">{activity.sobre}</p>
						</section>
					{/if}

					<!-- ===== Cronograma ===== -->
					{#if cronograma.length || outraLista.length}
						<section class="card" aria-labelledby="t-crono">
							<div class="crono-cab">
								<h2 id="t-crono" class="h2">Cronograma</h2>
								<p class="ajuda">
									{ehEvento
										? 'As etapas do evento.'
										: trilhaPrincipal === 'servo'
											? 'As etapas para quem vai servir.'
											: 'As etapas para campistas.'}
								</p>
							</div>
							<ol class="linha">
								{#each cronograma as it (it.k)}
									<li
										class="marco marco--{it.estado}"
										class:marco--prox={it.prox}
										class:marco--pessoal={it.pessoal}
										aria-current={it.atual ? 'step' : undefined}
									>
										<div class="marco-quando">
											<span class="marco-dia">{it.dia}</span>
											<span class="marco-sub">{it.sub}</span>
										</div>
										<div class="marco-trilho" aria-hidden="true">
											{#if !it.ultimo}<span class="marco-linha"></span>{/if}
											<span class="marco-ponto"></span>
										</div>
										<div class="marco-caixa" class:marco-caixa--destaque={it.destaque}>
											<div class="marco-titulo">
												<span>{it.t}</span>
												{#if it.tag}<span class="marco-tag">{it.tag}</span>{/if}
											</div>
											{#if it.desc}<span class="marco-desc">{it.desc}</span>{/if}
											{#if it.rel}<span class="marco-rel">{it.rel}</span>{/if}
										</div>
									</li>
								{/each}
							</ol>
							{#if outraTrilha && outraLista.length}
								<div class="outra">
									<button
										type="button"
										class="outra-botao"
										aria-expanded={servoAberto}
										onclick={() => (servoAberto = !servoAberto)}
									>
										<span aria-hidden="true" class="outra-seta" class:outra-seta--aberta={servoAberto}>›</span>
										{servoAberto ? 'Esconder' : 'Ver também'} o cronograma {outraTrilha === 'servo' ? 'dos servos' : 'dos campistas'}
									</button>
									{#if servoAberto}
										<ol class="outra-lista">
											{#each outraLista as o (o.t)}
												<li class:passou={o.passou}><span class="outra-quando">{o.quando}</span><strong>{o.t}</strong></li>
											{/each}
										</ol>
									{:else}
										<p class="ajuda">{resumoOutra}</p>
									{/if}
								</div>
							{:else}
								<div class="crono-fim"></div>
							{/if}
						</section>
					{/if}
				</div>

				<!-- ===== Valores e documentos ===== -->
				<aside class="lateral">
					<section class="card lateral-card" aria-labelledby="t-val">
						<h2 id="t-val" class="h2 h2-menor">Valores</h2>
						<dl class="valores">
							{#each linhasValores as l (l.quem)}
								<div>
									<dt>{l.quem}{#if l.voce}{' '}<span class="voce">Você</span>{/if}</dt>
									<dd>{l.valor}</dd>
								</div>
							{/each}
						</dl>
						{#if prazoDosValores}
							<p class="ajuda">Pagamento até <strong>{prazoDosValores}</strong>.</p>
						{/if}
						{#if pago && !gratis}
							<span class="pill t-ok pill-sozinha"><i></i>{vagaJaPaga ? 'Vaga já paga' : pagoEm ? `Pago em ${pagoEm}` : 'Pago'}</span>
						{/if}
						{#if notaValores}<p class="ajuda">{notaValores}</p>{/if}
					</section>

					{#if documentos.length}
						<section class="card lateral-card" aria-labelledby="t-docs">
							<h2 id="t-docs" class="h2 h2-menor">Documentos</h2>
							{#each documentos as d (d.nome)}
								<a href={d.url} target="_blank" rel="noopener noreferrer" class="doc">
									<span class="doc-icone" aria-hidden="true">{d.tipo}</span>
									<span class="doc-textos"><span class="doc-nome">{d.nome}</span><span class="doc-info">{d.info}</span></span>
									<span class="doc-abrir">Abrir</span>
								</a>
							{/each}
						</section>
					{/if}
				</aside>
			</div>
		{/if}
	</main>

	<!-- ===== Janela "Confirmar inscrição" ===== -->
	{#if janelaAberta && activity}
		<!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
		<div class="fundo" onclick={fecharJanela}>
			<!-- svelte-ignore a11y_click_events_have_key_events -->
			<div
				bind:this={janelaEl}
				class="janela"
				role="dialog"
				aria-modal="true"
				aria-labelledby="t-conf"
				tabindex="-1"
				onclick={(e) => e.stopPropagation()}
				onkeydown={teclaNaJanela}
			>
				<div class="faixa faixa-neutro"></div>
				<form class="janela-corpo" onsubmit={confirmar}>
					<h2 id="t-conf" class="janela-titulo">Confirmar inscrição</h2>
					{#if erroJanela}
						<div class="erro-janela" role="alert">
							<span class="erro-icone" aria-hidden="true">!</span>
							<span><strong>Não foi possível confirmar a inscrição.</strong> {erroJanela}</span>
						</div>
					{/if}
					<div class="janela-ativ">
						<div class="janela-ativ-textos">
							<span class="janela-ativ-nome">{activity.nome}</span>
							<span class="ajuda">{datas}{activity.local ? ` · ${activity.local}` : ''}</span>
						</div>
						<span class="janela-como">
							{janelaTrilha === 'servo' ? 'Servo(a)' : janelaTrilha === 'participante' ? 'Participante' : 'Campista'}
						</span>
					</div>
					{#if janelaTrilha === 'servo'}
						<div class="aviso av-azul">
							<i></i><span
								><strong>O que é servir:</strong> fazer parte da equipe que prepara e cuida do acampamento, em setores como cozinha, música
								e apoio. A coordenação define o setor de cada servo(a).</span
							>
						</div>
					{/if}
					<p class="janela-frase">{fraseJanela}</p>

					{#if pedeConjuge}
						<label class="lbl">
							CPF do cônjuge
							<input
								class="campo campo-cpf"
								class:erro={erroNoCpf}
								inputmode="numeric"
								placeholder="000.000.000-00"
								value={cpfConjuge}
								oninput={(e) => {
									cpfConjuge = mascaraCpf(e.currentTarget.value);
									e.currentTarget.value = cpfConjuge;
								}}
								aria-invalid={erroNoCpf}
							/>
							<span class="ajuda ajuda-normal">
								{ehCasais && janelaTrilha !== 'servo'
									? 'No acampamento de Casais, vocês se inscrevem juntos: seu cônjuge recebe um aviso no site para confirmar.'
									: 'Como você é casado(a) ou vive em união estável, informe o CPF do cônjuge.'} Seu cônjuge precisa ter uma conta no site.
							</span>
						</label>
					{/if}

					{#if pedeEndereco}
						<fieldset class="endereco">
							<legend>Onde você mora</legend>
							<p class="ajuda">A cidade e o CEP ficam salvos no seu cadastro.</p>
							<div class="endereco-grid">
								<label class="lbl"
									>CEP<input
										class="campo"
										inputmode="numeric"
										placeholder="00000-000"
										value={end.cep}
										oninput={(e) => {
											end.cep = e.currentTarget.value.replace(/\D/g, '').slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2');
											e.currentTarget.value = end.cep;
										}}
										required
									/></label
								>
								<label class="lbl">Cidade<input class="campo" bind:value={end.cidade} required /></label>
							</div>
						</fieldset>
					{/if}

					<div class="janela-acoes">
						<button type="button" class="btn b-azul" disabled={processando} onclick={fecharJanela}>Cancelar</button>
						{#if processando}
							<button type="button" class="btn b-ouro btn-largo" disabled aria-busy="true"><span class="spinner"></span>Processando…</button>
						{:else}
							<button type="submit" class="btn b-ouro btn-largo js-confirmar" disabled={invalido}>Confirmar inscrição</button>
						{/if}
					</div>
				</form>
			</div>
		</div>
	{/if}

	{#if toast}
		<div class="toast" role="status">
			<span>{toast}</span>
			<button type="button" aria-label="Fechar aviso" onclick={() => (toast = '')}>×</button>
		</div>
	{/if}
</div>

<style>
	:global(body) {
		margin: 0;
		background: #f7f2e7;
	}

	.pagina {
		--marfim: #f7f2e7;
		--pergaminho: #fffdf8;
		--azul: #1b2f5e;
		--azul-escuro: #0f1d3d;
		--ouro: #b69e55;
		--ouro-claro: #e2ce8a;
		--ouro-texto: #7a6528;
		--vermelho: #9b2c2c;
		--tinta: #1e2233;
		--tinta-2: #2b3045;
		--tinta-3: #4f5468;
		--borda: #d9cca6;
		--borda-suave: #e3d8bc;
		--campo-borda: #a39363;
		min-height: 100vh;
		background: var(--marfim);
		font-family: 'Source Sans 3', system-ui, sans-serif;
		color: var(--tinta);
	}

	.conteudo {
		max-width: 1200px;
		box-sizing: border-box;
		margin: 0 auto;
		padding: 22px 48px 64px;
		display: flex;
		flex-direction: column;
		gap: 24px;
	}

	.voltar {
		align-self: flex-start;
		display: flex;
		align-items: center;
		min-height: 40px;
		font-size: 16px;
		font-weight: 600;
		color: var(--azul);
		text-decoration: none;
	}

	.voltar:hover {
		color: var(--ouro-texto);
	}

	.estado {
		font-size: 17px;
		color: var(--tinta-3);
	}

	.estado-erro {
		padding: 28px 30px;
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 16px;
		font-size: 17px;
	}

	.card {
		background: var(--pergaminho);
		border: 1px solid var(--borda-suave);
		border-radius: 16px;
		box-shadow: 0 14px 28px -22px rgba(15, 29, 61, 0.45);
	}

	.h2 {
		margin: 0;
		font: 600 30px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.sobre {
		display: flex;
		flex-direction: column;
		gap: 14px;
		padding: 28px 32px;
	}

	.sobre-texto {
		margin: 0;
		font-size: 17px;
		line-height: 1.6;
		color: var(--tinta-2);
		white-space: pre-line;
	}

	.h2-menor {
		font-size: 26px;
	}

	.rot {
		font-size: 13px;
		font-weight: 700;
		letter-spacing: 0.1em;
		text-transform: uppercase;
		color: var(--ouro-texto);
	}

	.rot-cinza {
		color: var(--tinta-3);
	}

	.ajuda {
		margin: 0;
		font-size: 15px;
		line-height: 1.45;
		color: var(--tinta-3);
	}

	.ajuda strong {
		color: var(--tinta);
	}

	/* ===== Botões e selos ===== */
	.btn {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 10px;
		height: 52px;
		padding: 0 24px;
		box-sizing: border-box;
		font: 600 17px 'Source Sans 3', sans-serif;
		border-radius: 999px;
		cursor: pointer;
		white-space: nowrap;
		text-decoration: none;
	}

	.btn:disabled {
		cursor: not-allowed;
	}

	.btn:focus-visible,
	.voltar:focus-visible,
	.doc:focus-visible,
	.outra-botao:focus-visible,
	.criar-conta a:focus-visible {
		outline: none;
		box-shadow: 0 0 0 4px rgba(182, 158, 85, 0.45);
	}

	.b-ouro {
		font-weight: 700;
		color: var(--azul-escuro);
		background: linear-gradient(180deg, #d6be72, #b69e55);
		border: 1px solid #9c8642;
		box-shadow: 0 6px 16px -8px rgba(122, 101, 40, 0.9);
	}

	.b-ouro:hover {
		background: var(--ouro);
		color: var(--azul-escuro);
	}

	.b-ouro:disabled {
		color: #3a3420;
		background: #e3d6ae;
		border-color: #cdb878;
		box-shadow: none;
	}

	.b-azul {
		color: var(--azul);
		background: transparent;
		border: 1.5px solid var(--azul);
	}

	.b-azul:hover {
		background: #eef1f7;
	}

	.b-azul:disabled {
		color: #7c7f8e;
		border-color: #c9cfde;
	}

	.btn-largo {
		min-width: 220px;
	}

	.pill {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		font-size: 15px;
		font-weight: 700;
		border-radius: 8px;
		padding: 5px 12px;
		border: 1px solid;
		white-space: nowrap;
	}

	.pill i {
		width: 8px;
		height: 8px;
		border-radius: 50%;
		flex: 0 0 8px;
	}

	.pill-sozinha {
		align-self: flex-start;
	}

	.t-ok {
		color: #1b5236;
		background: #e4f1e8;
		border-color: #b9d9c4;
	}
	.t-ok i {
		background: #2e7d4f;
	}
	.t-espera {
		color: #6a480c;
		background: #fbf0d6;
		border-color: #ebd39c;
	}
	.t-espera i {
		background: #c08a1e;
	}
	.t-cancel {
		color: #842424;
		background: #f6e2df;
		border-color: #e6bdb6;
	}
	.t-cancel i {
		background: #b33a3a;
	}
	.t-pend {
		color: #21376a;
		background: #e6ebf5;
		border-color: #c4cfe6;
	}
	.t-pend i {
		background: #3c5aa0;
	}
	.t-cinza {
		color: #4f5468;
		background: #efebe1;
		border-color: #e3d8bc;
	}
	.t-cinza i {
		background: #8a8c99;
	}

	.aviso {
		display: flex;
		gap: 10px;
		align-items: flex-start;
		padding: 12px 14px;
		border-radius: 10px;
		font-size: 16px;
		line-height: 1.45;
	}

	.aviso > i {
		flex: 0 0 8px;
		height: 8px;
		margin-top: 8px;
		border-radius: 50%;
	}

	.av-amb {
		background: #fbf0d6;
		border: 1px solid #ebd39c;
		color: #6a480c;
	}
	.av-amb > i {
		background: #c08a1e;
	}
	.av-azul {
		background: #e6ebf5;
		border: 1px solid #c4cfe6;
		color: #21376a;
	}
	.av-azul > i {
		background: #3c5aa0;
	}

	/* ===== Topo ===== */
	.topo {
		position: relative;
		overflow: hidden;
		border-radius: 22px;
		background:
			radial-gradient(circle at 12% 30%, rgba(214, 186, 104, 0.35), transparent 28%),
			radial-gradient(circle at 70% 120%, rgba(31, 107, 110, 0.85), transparent 45%),
			radial-gradient(circle at 95% 0%, rgba(155, 44, 44, 0.35), transparent 30%),
			linear-gradient(150deg, #22397a 0%, #1b2f5e 45%, #0f1d3d 100%);
		box-shadow:
			inset 0 0 0 6px #0f1d3d,
			inset 0 0 0 7px rgba(182, 158, 85, 0.75);
	}

	.topo-chumbo {
		position: absolute;
		inset: 0;
		background:
			repeating-linear-gradient(45deg, transparent 0 46px, rgba(8, 14, 32, 0.32) 46px 48px),
			repeating-linear-gradient(-45deg, transparent 0 46px, rgba(8, 14, 32, 0.32) 46px 48px);
		pointer-events: none;
	}

	.topo-grid {
		position: relative;
		display: grid;
		grid-template-columns: minmax(0, 1fr) 300px;
		gap: 40px;
		align-items: end;
		padding: 44px 52px 0;
	}

	.topo-textos {
		display: flex;
		flex-direction: column;
		gap: 16px;
		padding-bottom: 44px;
	}

	.selos {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	.sel {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		font: 700 12px 'Source Sans 3', sans-serif;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		border-radius: 999px;
		padding: 5px 11px;
		white-space: nowrap;
	}

	.sel-acamp {
		color: var(--pergaminho);
		background: var(--azul);
		border: 1px solid var(--ouro);
	}
	.sel-evento {
		color: var(--pergaminho);
		background: var(--vermelho);
		border: 1px solid #d9a9a0;
	}
	.sel-cat {
		color: var(--pergaminho);
		background: rgba(255, 253, 248, 0.12);
		border: 1px solid rgba(226, 206, 138, 0.6);
	}
	.topo-servos {
		margin: -4px 0 0;
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 16px;
		color: var(--pergaminho);
	}

	.losango-servos {
		flex: 0 0 7px;
		width: 7px;
		height: 7px;
		background: var(--ouro-claro);
		transform: rotate(45deg);
	}

	.topo h1 {
		margin: 0;
		font: 600 54px/1.02 'Cormorant Garamond', serif;
		color: var(--pergaminho);
		text-wrap: balance;
	}

	.topo-dados {
		margin: 4px 0 0;
		display: flex;
		flex-wrap: wrap;
		gap: 14px 40px;
	}

	.topo-dados div {
		display: flex;
		flex-direction: column;
		gap: 3px;
	}

	.topo-dados dt {
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.14em;
		text-transform: uppercase;
		color: var(--ouro-claro);
	}

	.topo-dados dd {
		margin: 0;
		font-size: 18px;
		color: var(--pergaminho);
	}

	.arco {
		position: relative;
		height: 300px;
		border-radius: 50% 50% 0 0 / 34% 34% 0 0;
		overflow: hidden;
		box-shadow: inset 0 0 0 1px #cdb878;
	}

	.arco-foto {
		position: absolute;
		inset: 0;
		width: 100%;
		height: 100%;
		object-fit: cover;
	}

	.arco-vitral {
		position: absolute;
		inset: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		background:
			repeating-linear-gradient(45deg, transparent 0 26px, rgba(8, 14, 32, 0.45) 26px 28px),
			repeating-linear-gradient(-45deg, transparent 0 26px, rgba(8, 14, 32, 0.45) 26px 28px),
			radial-gradient(circle at 25% 25%, rgba(226, 196, 110, 0.55), transparent 35%),
			radial-gradient(circle at 80% 75%, rgba(31, 107, 110, 0.9), transparent 45%),
			radial-gradient(circle at 85% 15%, rgba(155, 44, 44, 0.45), transparent 30%),
			linear-gradient(160deg, #25407f, #1b2f5e 50%, #0f1d3d);
	}

	.arco-vitral img {
		width: 210px;
		margin-top: -10px;
		filter: brightness(1.2) drop-shadow(0 2px 6px rgba(0, 0, 0, 0.4));
	}

	.arco-moldura {
		position: absolute;
		inset: 0;
		border-radius: inherit;
		box-shadow:
			inset 0 0 0 6px rgba(255, 253, 248, 0.85),
			inset 0 0 0 7px rgba(182, 158, 85, 0.85);
		pointer-events: none;
	}

	/* ===== Corpo ===== */
	.corpo {
		display: grid;
		grid-template-columns: minmax(0, 1fr) 360px;
		gap: 24px;
		align-items: start;
	}

	.coluna,
	.lateral {
		display: flex;
		flex-direction: column;
		gap: 24px;
		min-width: 0;
	}

	/* ===== Sua inscrição ===== */
	.insc {
		overflow: hidden;
	}

	.faixa {
		height: 6px;
	}
	.faixa-ok {
		background: #2e7d4f;
	}
	.faixa-espera {
		background: #c08a1e;
	}
	.faixa-cancel {
		background: #9b2c2c;
	}
	.faixa-pend {
		background: #3c5aa0;
	}
	.faixa-cinza {
		background: #8a8c99;
	}
	.faixa-neutro {
		background: linear-gradient(90deg, #1b2f5e, #1f6b6e 40%, #b69e55 70%, #9b2c2c);
	}

	.insc-corpo {
		display: flex;
		flex-direction: column;
		gap: 18px;
		padding: 24px 30px 28px;
	}

	.insc-cab {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		flex-wrap: wrap;
	}

	.insc-textos {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.insc-textos h2 {
		margin: 0;
		font: 600 32px/1.12 'Cormorant Garamond', serif;
		color: var(--azul);
		text-wrap: balance;
	}

	.insc-textos p {
		margin: 0;
		max-width: 680px;
		font-size: 17px;
		line-height: 1.5;
		color: var(--tinta-2);
	}

	.motivo {
		margin: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 16px 20px;
		background: #fff;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}

	.motivo blockquote {
		margin: 0;
		font: 500 19px/1.45 'Source Sans 3', sans-serif;
		color: var(--tinta);
	}

	.motivo figcaption {
		font-size: 15px;
		color: var(--tinta-3);
	}

	.passo {
		display: flex;
		align-items: center;
		gap: 20px;
		flex-wrap: wrap;
		padding: 16px 20px;
		background: #eef1f7;
		border: 1px solid #c4cfe6;
		border-radius: 12px;
	}

	.passo--alerta {
		background: #fbf0d6;
		border-color: #ebd39c;
	}

	.passo-textos {
		flex: 1 1 260px;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.passo-rot {
		color: #21376a;
	}

	.passo--alerta .passo-rot {
		color: #6a480c;
	}

	.passo-data {
		font: 600 28px/1.1 'Cormorant Garamond', serif;
		color: var(--tinta);
	}

	.passo-aviso {
		font-size: 16px;
		line-height: 1.45;
		font-weight: 600;
		color: #842424;
	}

	.passo-rel {
		flex: 0 0 auto;
		font-size: 16px;
		font-weight: 700;
		color: var(--azul);
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 999px;
		padding: 6px 14px;
	}

	.pagamento {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		flex-wrap: wrap;
		padding: 16px 20px;
		background: #fff;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}

	.pagamento-textos {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.pagamento-valor {
		font-size: 18px;
		font-weight: 700;
	}

	.pagamento-linha {
		font-size: 16px;
		color: var(--tinta-3);
	}

	.opcoes {
		display: grid;
		gap: 14px;
	}

	.opcao {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 18px 20px;
		background: #fff;
		border: 1px solid var(--borda);
		border-radius: 12px;
	}

	.opcao .btn {
		margin-top: 4px;
	}

	.opcao--nao {
		background: #f4f0e6;
		border-color: var(--borda-suave);
	}

	.opcao-nome {
		font: 600 26px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.opcao--nao .opcao-nome {
		color: #5e6272;
	}

	.opcao-desc {
		font-size: 16px;
		line-height: 1.45;
		color: var(--tinta-2);
	}

	.opcao-porque {
		display: flex;
		gap: 8px;
		font-size: 16px;
		line-height: 1.45;
		color: var(--tinta-3);
	}

	.opcao-porque > span {
		flex: 0 0 8px;
		height: 8px;
		margin-top: 8px;
		border-radius: 50%;
		background: #8a8c99;
	}

	.acoes {
		display: flex;
		flex-wrap: wrap;
		gap: 10px;
		align-items: center;
	}

	.criar-conta {
		font-size: 16px;
		color: var(--tinta-3);
	}

	.criar-conta a {
		font-weight: 600;
		color: var(--azul);
	}

	/* ===== Cronograma ===== */
	.crono-cab {
		display: flex;
		flex-direction: column;
		gap: 6px;
		padding: 24px 30px 6px;
	}

	.crono-cab .ajuda {
		font-size: 16px;
	}

	.linha {
		margin: 0;
		padding: 18px 30px 8px;
		list-style: none;
		display: flex;
		flex-direction: column;
	}

	.marco {
		position: relative;
		display: grid;
		grid-template-columns: 132px 32px minmax(0, 1fr);
		column-gap: 16px;
		padding-bottom: 22px;
	}

	.marco:last-child {
		padding-bottom: 6px;
	}

	.marco-quando {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		text-align: right;
		gap: 2px;
		padding-top: 1px;
	}

	.marco-dia {
		font: 600 22px/1.1 'Cormorant Garamond', serif;
		color: var(--tinta);
		white-space: nowrap;
	}

	.marco-sub {
		font-size: 14px;
		color: var(--tinta-3);
		white-space: nowrap;
	}

	.marco-trilho {
		position: relative;
		display: flex;
		justify-content: center;
	}

	.marco-linha {
		position: absolute;
		top: 22px;
		bottom: -4px;
		left: 15px;
		width: 2px;
		background: var(--borda-suave);
	}

	.marco--pass .marco-linha {
		background: var(--azul);
	}

	.marco-ponto {
		position: relative;
		margin-top: 7px;
		width: 14px;
		height: 14px;
		box-sizing: border-box;
		border-radius: 50%;
		background: #fff;
		border: 2px solid var(--campo-borda);
	}

	.marco--pass .marco-ponto {
		background: var(--azul);
		border-color: var(--azul);
	}

	.marco--agora .marco-ponto {
		margin-top: 4px;
		width: 20px;
		height: 20px;
		background: var(--ouro);
		border: 2px solid #9c8642;
		box-shadow: 0 0 0 5px rgba(182, 158, 85, 0.28);
	}

	.marco--prox .marco-ponto {
		margin-top: 5px;
		width: 18px;
		height: 18px;
		border: 3px solid var(--azul);
	}

	.marco-caixa {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 2px 0 0;
		border: 1px solid transparent;
		border-radius: 12px;
	}

	.marco-caixa--destaque {
		padding: 12px 16px;
		background: #fbf6e6;
		border-color: var(--borda-suave);
	}

	.marco--pessoal .marco-caixa--destaque {
		background: #fff8e6;
		border-color: #ebd39c;
	}

	.marco-titulo {
		display: flex;
		align-items: center;
		gap: 10px;
		flex-wrap: wrap;
		font-size: 18px;
		font-weight: 700;
		color: var(--tinta);
	}

	.marco-tag {
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		border-radius: 999px;
		padding: 3px 9px;
		color: var(--azul);
		background: #e6ebf5;
	}

	.marco--agora .marco-tag {
		color: var(--azul-escuro);
		background: var(--ouro-claro);
	}

	.marco--pessoal .marco-tag {
		color: #6a480c;
		background: #f6e3b5;
	}

	.marco-desc {
		font-size: 16px;
		line-height: 1.45;
		color: var(--tinta-2);
	}

	.marco-rel {
		font-size: 15px;
		font-weight: 700;
		color: var(--azul);
	}

	.marco--pass .marco-dia,
	.marco--pass .marco-desc {
		color: #5e6272;
	}

	.marco--pass .marco-titulo {
		color: var(--tinta-3);
	}

	.outra {
		display: flex;
		flex-direction: column;
		gap: 12px;
		margin: 10px 30px 26px;
		padding-top: 16px;
		border-top: 1px solid #efe7d3;
	}

	.outra-botao {
		align-self: flex-start;
		display: flex;
		align-items: center;
		gap: 8px;
		min-height: 44px;
		padding: 0;
		border: none;
		background: transparent;
		font: 600 16px 'Source Sans 3', sans-serif;
		color: var(--azul);
		cursor: pointer;
		border-radius: 6px;
	}

	.outra-seta {
		display: inline-block;
		transition: transform 0.15s;
	}

	.outra-seta--aberta {
		transform: rotate(90deg);
	}

	.outra-lista {
		margin: 0;
		padding: 6px 0 0;
		list-style: none;
		display: grid;
		grid-template-columns: 170px minmax(0, 1fr);
		gap: 10px 16px;
		font-size: 16px;
		color: var(--tinta-2);
	}

	.outra-lista li {
		display: contents;
	}

	.outra-lista strong {
		font-weight: 600;
	}

	.outra-lista .passou {
		color: #5e6272;
	}

	.outra-quando {
		font-variant-numeric: tabular-nums;
	}

	.crono-fim {
		height: 18px;
	}

	/* ===== Valores e documentos ===== */
	.lateral-card {
		display: flex;
		flex-direction: column;
		gap: 14px;
		padding: 24px 26px 26px;
	}

	.valores {
		margin: 0;
		display: flex;
		flex-direction: column;
	}

	.valores div {
		display: flex;
		justify-content: space-between;
		align-items: baseline;
		gap: 12px;
		padding: 10px 0;
		border-top: 1px solid #efe7d3;
	}

	.valores dt {
		font-size: 17px;
		color: var(--tinta-2);
	}

	.valores dd {
		margin: 0;
		font-size: 18px;
		font-weight: 700;
		font-variant-numeric: tabular-nums;
	}

	.voce {
		font-size: 12px;
		font-weight: 700;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--ouro-claro);
		background: var(--azul);
		border-radius: 999px;
		padding: 2px 8px;
		vertical-align: 2px;
	}

	.doc {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 12px 14px;
		background: #fff;
		border: 1px solid var(--borda-suave);
		border-radius: 10px;
		text-decoration: none;
		color: var(--tinta);
	}

	.doc:hover {
		border-color: var(--ouro);
		background: #fbf6e6;
	}

	.doc-icone {
		flex: 0 0 36px;
		height: 44px;
		border-radius: 6px;
		background: #f1e8cf;
		color: #6b5822;
		font-size: 12px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.doc-textos {
		flex: 1;
		display: flex;
		flex-direction: column;
		gap: 1px;
	}

	.doc-nome {
		font-size: 16px;
		font-weight: 600;
	}

	.doc-info {
		font-size: 14px;
		color: var(--tinta-3);
	}

	.doc-abrir {
		font-size: 15px;
		font-weight: 600;
		color: var(--azul);
	}

	/* ===== Janela ===== */
	.fundo {
		position: fixed;
		inset: 0;
		z-index: 20;
		display: flex;
		align-items: flex-start;
		justify-content: center;
		padding: 40px 24px;
		background: rgba(15, 29, 61, 0.58);
		overflow: auto;
	}

	.janela {
		width: 100%;
		max-width: 640px;
		margin-top: 40px;
		background: var(--pergaminho);
		border: 1px solid var(--borda);
		border-radius: 18px;
		overflow: hidden;
		box-shadow: 0 40px 80px -30px rgba(8, 14, 32, 0.7);
		outline: none;
	}

	.janela-corpo {
		display: flex;
		flex-direction: column;
		gap: 20px;
		padding: 30px 32px 28px;
	}

	.janela-titulo {
		margin: 0;
		font: 600 32px/1.1 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.erro-janela {
		display: flex;
		gap: 12px;
		align-items: flex-start;
		padding: 14px 16px;
		background: #f6e2df;
		border: 1px solid #e6bdb6;
		border-radius: 10px;
		color: #842424;
		font-size: 16px;
		line-height: 1.45;
	}

	.erro-icone {
		flex: 0 0 24px;
		height: 24px;
		border-radius: 50%;
		background: var(--vermelho);
		color: var(--pergaminho);
		font-size: 15px;
		font-weight: 700;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.janela-ativ {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		padding: 16px 18px;
		background: #fff;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
	}

	.janela-ativ-textos {
		display: flex;
		flex-direction: column;
		gap: 3px;
	}

	.janela-ativ-nome {
		font: 600 24px/1.15 'Cormorant Garamond', serif;
	}

	.janela-como {
		flex: 0 0 auto;
		font-size: 14px;
		font-weight: 700;
		letter-spacing: 0.06em;
		text-transform: uppercase;
		color: var(--pergaminho);
		background: var(--azul);
		border: 1px solid var(--ouro);
		border-radius: 999px;
		padding: 5px 12px;
	}

	.janela-frase {
		margin: 0;
		font-size: 17px;
		line-height: 1.5;
		color: var(--tinta-2);
	}

	.lbl {
		display: flex;
		flex-direction: column;
		gap: 8px;
		font-size: 16px;
		font-weight: 600;
		color: var(--tinta);
	}

	.lbl-inteira {
		grid-column: 1 / -1;
	}

	.ajuda-normal {
		font-weight: 400;
	}

	.campo {
		width: 100%;
		box-sizing: border-box;
		height: 52px;
		padding: 0 16px;
		font: 400 17px 'Source Sans 3', sans-serif;
		color: var(--tinta);
		background: #fff;
		border: 1.5px solid var(--campo-borda);
		border-radius: 10px;
		outline: none;
	}

	.campo:focus {
		border-color: var(--azul);
		box-shadow: 0 0 0 1px var(--azul);
	}

	.campo.erro {
		border-color: var(--vermelho);
		box-shadow: 0 0 0 1px var(--vermelho);
	}

	.campo-cpf {
		max-width: 280px;
		font-variant-numeric: tabular-nums;
	}

	.endereco {
		margin: 0;
		padding: 18px 18px 20px;
		border: 1px solid var(--borda-suave);
		border-radius: 12px;
		background: #fff;
		display: flex;
		flex-direction: column;
		gap: 14px;
	}

	.endereco legend {
		padding: 0 6px;
		font: 600 22px 'Cormorant Garamond', serif;
		color: var(--azul);
	}

	.endereco-grid {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 14px 16px;
	}

	.janela-acoes {
		display: flex;
		justify-content: flex-end;
		gap: 10px;
	}

	.spinner {
		width: 16px;
		height: 16px;
		box-sizing: border-box;
		border-radius: 50%;
		border: 2.5px solid rgba(15, 29, 61, 0.2);
		border-top-color: var(--azul-escuro);
		animation: girar 0.8s linear infinite;
	}

	@keyframes girar {
		to {
			transform: rotate(360deg);
		}
	}

	.toast {
		position: fixed;
		left: 50%;
		bottom: 28px;
		transform: translateX(-50%);
		z-index: 30;
		display: flex;
		align-items: center;
		gap: 14px;
		padding: 14px 12px 14px 20px;
		background: var(--azul-escuro);
		color: var(--pergaminho);
		border: 1px solid rgba(182, 158, 85, 0.6);
		border-radius: 12px;
		font-size: 16px;
		box-shadow: 0 20px 40px -16px rgba(8, 14, 32, 0.7);
	}

	.toast button {
		width: 36px;
		height: 36px;
		border: none;
		background: transparent;
		color: #e6e9f2;
		font-size: 20px;
		cursor: pointer;
	}

	@media (prefers-reduced-motion: reduce) {
		.spinner,
		.outra-seta {
			animation: none;
			transition: none;
		}
	}
</style>
