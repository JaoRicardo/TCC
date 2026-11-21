/**
 * Faixa etária do evento (idade mínima e/ou máxima). A idade é contada no
 * primeiro dia do evento; quem está fora não consegue se inscrever (o servidor
 * confere em InscricoesController::foraDaFaixaEtaria).
 */

/** "De 18 a 35 anos", "A partir de 15 anos", "Até 12 anos"; '' sem faixa. */
export function textoDaFaixa(min: number | null | undefined, max: number | null | undefined): string {
	const temMin = min !== null && min !== undefined;
	const temMax = max !== null && max !== undefined;
	if (temMin && temMax) return `De ${min} a ${max} anos`;
	if (temMin) return `A partir de ${min} anos`;
	if (temMax) return `Até ${max} anos`;
	return '';
}

/** O mesmo texto para o meio de uma frase: "pessoas de 18 a 35 anos". */
export const faixaNaFrase = (min: number | null | undefined, max: number | null | undefined) => {
	const t = textoDaFaixa(min, max);
	return t ? t.charAt(0).toLowerCase() + t.slice(1) : '';
};
