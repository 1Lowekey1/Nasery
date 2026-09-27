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

  runtimeConfig: {
    public: {
      apiBase: 'http://localhost:8000/api/v1',
      backendUrl: 'http://localhost:8000',
      supabaseUrl: '',
      supabaseKey: '',
    },
  },
})