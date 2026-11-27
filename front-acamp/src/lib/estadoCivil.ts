/**
 * Os estados civis vêm do banco no masculino ("Casado", "Solteiro"…). Para
 * falar com a pessoa, o texto acompanha o sexo do cadastro: "Casada" para uma
 * mulher. Os que não mudam ("União estável", "Namorando", "Segunda união")
 * ficam como estão.
 */
const NO_FEMININO: Record<string, string> = {
	Solteiro: 'Solteira',
	Casado: 'Casada',
	Viúvo: 'Viúva',
	Separado: 'Separada',
	Divorciado: 'Divorciada'
};

export function estadoCivilNoGenero(titulo: string, sexo: string | null | undefined): string {
	if (sexo !== 'F') return titulo;
	return NO_FEMININO[titulo] ?? titulo;
}
