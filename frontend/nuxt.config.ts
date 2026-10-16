// frontend/nuxt.config.ts
export default defineNuxtConfig({
  compatibilityDate: '2024-11-01',
  devtools: { enabled: true },
  css: ['~/assets/css/tailwind.css'],
  postcss: {
    plugins: {
      tailwindcss: {},
      autoprefixer: {},
    },
  },
  
  // CRITICAL: Disable SSR to allow seamless Laravel Sanctum HttpOnly cookie auth
  ssr: false,

  // Start-up screen with the NaSeRy logo (app/spa-loading-template.html). Nuxt
  // would remove it as soon as the first page component is ready; the empty id
  // turns that off so app/plugins/splash.client.ts can fade it out after the
  // page's data has arrived too.
  spaLoadingTemplate: 'spa-loading-template.html',
  app: {
    spaLoaderAttrs: { id: '' },
    head: {
      // NaSeRy logo (assets/Nasery_ICO.ico, trimmed to 16/32/48 px). Bump ?v= when it
      // changes: browsers keep tab icons cached for a long time.
      link: [{ rel: 'icon', type: 'image/x-icon', href: '/favicon.ico?v=2' }],
    },
  },

  runtimeConfig: {
    public: {
      apiBase: 'http://localhost:8000/api/v1',
      backendUrl: 'http://localhost:8000',
      supabaseUrl: '',
      supabaseKey: '',
      // Used by the redesigned pages (frontend update, 2026-09-28).
      appName: 'NaSeRy',
      apiBaseURL: 'http://localhost:8000/api/v1',
      appBaseURL: 'http://localhost:3000',
      // Address search (free LocationIQ key); the field says "not configured" without one.
      locationIqApiKey: '',
    },
  },
})