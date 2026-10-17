import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

const buildId = String(Date.now())

// База '/' — кладём собранный сайт в корень public_html на reg.ru.
export default defineConfig({
  define: { __APP_BUILD_ID__: JSON.stringify(buildId) },
  plugins: [
    react(),
    {
      name: 'stobalnik-build-version',
      generateBundle() {
        this.emitFile({
          type: 'asset',
          fileName: 'version.json',
          source: JSON.stringify({ version: buildId }),
        })
      },
    },
  ],
  server: { port: 5173, open: true },
  build: { outDir: 'dist' },
})
