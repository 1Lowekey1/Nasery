// One chart palette for the whole app (user review 2026-10-25: the all-teal shades were
// hard to tell apart). Clearly different hues in a fixed order, checked with the
// data-viz validator (light surface): blue, orange, aqua, yellow pass the colorblind
// and normal-vision separation checks. The lighter ones sit below 3:1 contrast, so
// every chart keeps visible labels (legend list with counts).
//
// Rules: a series keeps its color everywhere (color follows the thing, not its rank);
// single-series charts stay brand teal; grey means "none / inactive", never a series.
export const CHART = {
  blue: '#2a78d6',
  orange: '#eb6834',
  aqua: '#1baf7a',
  yellow: '#eda100',
  brand: '#285F6b', // single-series charts
  grey: '#9ca3af', // inactive / cancelled / rejected / not yet
  greyLight: '#d1d5db',
  track: '#e5e7eb', // the empty part of a gauge or progress donut
  gap: '#ffffff', // 2px surface gap between slices
} as const

export function eventStatusColor(status: string): string {
  return ({
    draft: CHART.yellow,
    published: CHART.blue,
    ongoing: CHART.orange,
    completed: CHART.aqua,
    cancelled: CHART.grey,
  } as Record<string, string>)[status] ?? CHART.greyLight
}

export function quotationStatusColor(status: string): string {
  return ({
    submitted: CHART.blue,
    pending: CHART.blue,
    accepted: CHART.aqua,
    rejected: CHART.grey,
    withdrawn: CHART.greyLight,
  } as Record<string, string>)[status] ?? CHART.greyLight
}
