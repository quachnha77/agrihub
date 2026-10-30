import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vitest/config'

// https://vite.dev/config/
const apiTarget = process.env.VITE_API_PROXY ?? 'http://localhost:8000'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    // Gọi API Laravel qua cùng origin trong môi trường dev (tránh CORS, dùng được cookie SPA).
    // /sanctum cấp cookie CSRF cho phiên đăng nhập.
    // changeOrigin: false là CÓ CHỦ ĐÍCH: mỗi doanh nghiệp một subdomain (vd. nong-phat.localhost:5173), máy chủ cần thấy đúng Host gốc
    // để biết đó là doanh nghiệp nào.
    proxy: {
      '/api': { target: apiTarget, changeOrigin: false },
      '/sanctum': { target: apiTarget, changeOrigin: false },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    restoreMocks: true,
  },
})
