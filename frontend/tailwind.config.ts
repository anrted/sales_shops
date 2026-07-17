import type { Config } from 'tailwindcss'

export default {
  content: [
    './components/**/*.{vue,js,ts}',
    './layouts/**/*.vue',
    './pages/**/*.vue',
    './app.vue'
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'Arial', 'sans-serif']
      },
      colors: {
        ink: '#101828',
        muted: '#667085',
        line: '#d0d5dd',
        brand: '#1570ef',
        deal: '#039855',
        warn: '#dc6803'
      }
    }
  },
  plugins: []
} satisfies Config
