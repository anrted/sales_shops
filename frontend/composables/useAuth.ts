import { ref } from 'vue'
import { useCookie, useState, useRuntimeConfig, navigateTo } from '#app'

export const useAuth = () => {
  const token = useCookie<string | null>('auth_token', {
    path: '/',
    sameSite: 'lax',
    maxAge: 60 * 60 * 24 * 7
  })
  const user = useState('user', () => null)
  const config = useRuntimeConfig()
  const apiUrl = config.public.apiBase || 'http://localhost:8000/api'

  const login = async (email, password) => {
    try {
      const response = await $fetch(`${apiUrl}/auth/login`, {
        method: 'POST',
        body: { email, password }
      })
      token.value = response.token
      user.value = response.user
      return true
    } catch (e) {
      console.error('Login failed', e)
      return false
    }
  }

  const logout = async () => {
    if (token.value) {
      try {
        const api = useApi()
        await api('/auth/logout', { method: 'POST' })
      } catch (e) {
        // ignore
      }
    }
    token.value = null
    user.value = null
    navigateTo('/admin/login')
  }

  const fetchUser = async () => {
    if (!token.value) return null
    try {
      const api = useApi()
      const response = await api<{ user: any }>('/auth/me')
      user.value = response.user
      return user.value
    } catch (e) {
      token.value = null
      user.value = null
      return null
    }
  }

  return {
    token,
    user,
    login,
    logout,
    fetchUser
  }
}
