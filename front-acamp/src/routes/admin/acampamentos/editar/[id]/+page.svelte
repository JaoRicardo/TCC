<script lang="ts">
	import { page } from "$app/stores";
	import AcampamentoForm from "$lib/components/AcampamentoForm.svelte";

	const id = $page.params.id as string;
</script>

<AcampamentoForm activityId={id} />
