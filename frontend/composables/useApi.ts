export function useApi() {
  const config = useRuntimeConfig()

  return $fetch.create({
    baseURL: config.public.apiBase,
    onRequest({ request, options }) {
      const token = useCookie('auth_token')
      const headers = new Headers(options.headers || {})
      headers.set('Accept', 'application/json')
      
      if (token.value) {
        headers.set('Authorization', `Bearer ${token.value}`)
      }

      options.headers = headers
    },
    onResponseError({ response }) {
      if (response.status === 401) {
        const token = useCookie('auth_token')
        token.value = null
      }
      if (response._data && typeof response._data === 'object' && response._data.message) {
        response.statusText = response._data.message
      }
    }
  })
}
