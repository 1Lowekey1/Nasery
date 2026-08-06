/** @type {import('tailwindcss').Config} */
module.exports = {
	content: ['./app/**/*.{vue,js,ts,jsx,tsx}'],
	theme: {
		extend: {
			colors: {
				primary: {
					50: '#eefbf8',
					100: '#d7f5ee',
					200: '#afe9db',
					300: '#7fd6c2',
					400: '#4fc1a8',
					500: '#26a98d',
					600: '#1c8a74',
					700: '#156f5e',
					800: '#12594c',
					900: '#0f4a40',
				},
			},
		},
	},
}