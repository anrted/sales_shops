export default defineNuxtConfig({
  compatibilityDate: '2026-05-13',
  modules: ['@nuxtjs/tailwindcss'],
  css: ['~/assets/css/main.css'],
  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8080/api',
      yandexMapsApiKey: process.env.NUXT_PUBLIC_YANDEX_MAPS_API_KEY || ''
    }
  },
  typescript: {
    strict: true
  },
  experimental: {
    appManifest: false
  },
  app: {
    head: {
      title: 'Скидки в магазинах',
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'description', content: 'Поиск выгодных товаров по скидкам в магазинах города.' }
      ]
    }
  }
})
