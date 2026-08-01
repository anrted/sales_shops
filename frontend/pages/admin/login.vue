<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-100 p-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-sm p-8">
      <h2 class="text-2xl font-semibold mb-6 text-center text-gray-800">Admin Login</h2>
      <form @submit.prevent="handleLogin" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input
            v-model="email"
            type="email"
            required
            class="w-full px-4 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all"
            placeholder="admin@example.com"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
          <input
            v-model="password"
            type="password"
            required
            class="w-full px-4 py-2 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all"
            placeholder="••••••••"
          />
        </div>
        <div v-if="error" class="text-red-500 text-sm text-center">
          {{ error }}
        </div>
        <button
          type="submit"
          :disabled="loading"
          class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-xl transition-colors disabled:opacity-50"
        >
          <span v-if="loading">Signing in...</span>
          <span v-else>Sign In</span>
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '~/composables/useAuth'

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

const router = useRouter()
const { login } = useAuth()

const handleLogin = async () => {
  loading.value = true
  error.value = ''
  
  const success = await login(email.value, password.value)
  if (success) {
    router.push('/admin')
  } else {
    error.value = 'Invalid email or password'
  }
  
  loading.value = false
}
</script>
