export function useApi() {
  const config = useRuntimeConfig()

  return $fetch.create({
    baseURL: config.public.apiBase,
    headers: {
      Accept: 'application/json'
    }
  })
}
