// frontend/composables/useApi.ts
export const useApi = () => {
  const config = useRuntimeConfig();

  return $fetch.create({
    baseURL: config.public.apiBase,
    credentials: 'include', // Automatically passes HttpOnly cookies to/from Laravel
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    }
  });
};