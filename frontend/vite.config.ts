import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    // Gọi API Laravel qua cùng origin trong môi trường dev (tránh CORS, dùng được cookie SPA)
    proxy: { '/api': { target: process.env.VITE_API_PROXY ?? 'http://localhost:8000', changeOrigin: true } },
  },
})
