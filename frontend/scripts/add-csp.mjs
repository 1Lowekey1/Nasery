// Runs after `npm run generate` (package.json "postgenerate").
//
// Adds a Content-Security-Policy <meta> tag to every built page. Nuxt puts two
// small inline scripts in each page (the import map and the runtime config),
// and they change with every build, so the policy allows exactly those by
// their SHA-256 hash instead of allowing all inline scripts. Anything else a
// visitor could get onto the page as a <script> will not run.
//
// frame-ancestors can't go in a <meta> tag; Apache sends X-Frame-Options and
// frame-ancestors as headers (backend/server/apache-local.conf).
import { createHash } from 'node:crypto'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

const root = new URL('../.output/public/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')

function htmlFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) return entry.name === '_nuxt' ? [] : htmlFiles(path)
		return entry.name.endsWith('.html') ? [path] : []
	})
}

function policy(hashes) {
	return [
		"default-src 'self'",
		`script-src 'self' ${hashes.join(' ')}`,
		// Vue, ApexCharts and Leaflet set style attributes at runtime.
		"style-src 'self' 'unsafe-inline'",
		// Uploaded photos come from /api/v1/media; map tiles from LocationIQ / OpenStreetMap.
		"img-src 'self' data: blob: https:",
		"font-src 'self' data:",
		// Address search (LocationIQ) is the only API the browser calls directly.
		"connect-src 'self' https://api.locationiq.com https://us1.locationiq.com",
		"media-src 'self' blob:",
		// The admin's and owner's document preview shows a local blob of the PDF.
		"frame-src blob:",
		"worker-src 'self' blob:",
		"object-src 'none'",
		"base-uri 'self'",
		"form-action 'self'",
	].join('; ')
}

const files = htmlFiles(root)
for (const file of files) {
	const html = readFileSync(file, 'utf8')
	if (html.includes('http-equiv="Content-Security-Policy"')) continue
	const hashes = [...html.matchAll(/<script(?![^>]*\ssrc=)([^>]*)>([\s\S]*?)<\/script>/g)]
		// JSON data blocks are never executed, so the policy doesn't apply to them.
		.filter(([, attrs]) => !/type="application\/json"/.test(attrs))
		.map(([, , body]) => `'sha256-${createHash('sha256').update(body, 'utf8').digest('base64')}'`)
	const meta = `<meta http-equiv="Content-Security-Policy" content="${policy([...new Set(hashes)])}">`
	// Right after <head>: the policy only covers scripts that come after it.
	writeFileSync(file, html.replace(/<head>/, `<head>${meta}`))
}
console.log(`Content-Security-Policy added to ${files.length} pages.`)
