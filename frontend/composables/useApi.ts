export function useApi() {
  const config = useRuntimeConfig()

  return $fetch.create({
    baseURL: config.public.apiBase,
    onRequest({ request, options }) {
      const token = useCookie('auth_token')
      options.headers = options.headers || {}
      options.headers['Accept'] = 'application/json'
      
      if (token.value) {
        options.headers['Authorization'] = `Bearer ${token.value}`
      }
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
