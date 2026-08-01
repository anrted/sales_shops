import { defineNuxtRouteMiddleware, navigateTo } from '#app'
import { useAuth } from '../composables/useAuth'

export default defineNuxtRouteMiddleware(async (to, from) => {
  const { token, fetchUser, user } = useAuth()

  if (!token.value) {
    return navigateTo('/admin/login')
  }

  if (!user.value) {
    await fetchUser()
  }

  if (!user.value || user.value.role !== 'admin') {
    return navigateTo('/admin/login')
  }
})
