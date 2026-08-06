import { createClient } from '@supabase/supabase-js'

export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig()

  const supabaseUrl: string = config.public.supabaseUrl as string
  const supabaseKey: string = config.public.supabaseKey as string

  if (!supabaseUrl || !supabaseKey) {
    throw new Error('Missing NUXT_PUBLIC_SUPABASE_URL or NUXT_PUBLIC_SUPABASE_KEY in .env')
  }

  const supabase = createClient(
    supabaseUrl,
    supabaseKey
  )

  return {
    provide: { supabase },
  }
})