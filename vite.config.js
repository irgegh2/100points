import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// База '/' — кладём собранный сайт в корень public_html на reg.ru.
export default defineConfig({
  plugins: [react()],
  server: { port: 5173, open: true },
  build: { outDir: 'dist' },
})
