import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  cacheDir: '.vite-cache',
  test: {
    environment: 'happy-dom',
    globals: true
  },
  resolve: {
    alias: {
      '~': new URL('.', import.meta.url).pathname
    }
  }
})
