export function useApi() {
  const config = useRuntimeConfig()
  const token = useCookie('auth_token')

  return $fetch.create({
    baseURL: config.public.apiBase,
    onRequest({ request, options }) {
      options.headers = options.headers || {}
      options.headers['Accept'] = 'application/json'
      
      if (token.value) {
        options.headers['Authorization'] = `Bearer ${token.value}`
      }
    },
    onResponseError({ response }) {
      if (response.status === 401) {
        token.value = null
      }
      if (response._data && typeof response._data === 'object' && response._data.message) {
        response.statusText = response._data.message
      }
    }
  })
}
