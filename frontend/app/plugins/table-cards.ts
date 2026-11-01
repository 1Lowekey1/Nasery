/**
 * v-table-cards: on phones the table's rows turn into cards (CSS in
 * tailwind.css, `.table-cards`). This copies each column header onto its
 * cells as data-label so every value keeps its name. A <th data-label="…">
 * overrides the header text (e.g. headers with badges or no text).
 */
function labelCells(table: HTMLElement) {
	const heads = [...table.querySelectorAll('thead th')].map(th =>
		(th.getAttribute('data-label') ?? th.textContent ?? '').trim(),
	)
	table.querySelectorAll('tbody tr').forEach((tr) => {
		[...tr.children].forEach((cell, i) => {
			if (cell.hasAttribute('colspan')) return
			cell.setAttribute('data-label', heads[i] ?? '')
		})
	})
}

export default defineNuxtPlugin((nuxtApp) => {
	nuxtApp.vueApp.directive('table-cards', {
		mounted(el: HTMLElement) {
			el.classList.add('table-cards')
			labelCells(el)
		},
		updated: labelCells,
		getSSRProps: () => ({ class: 'table-cards' }),
	})
})
