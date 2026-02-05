import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    manifest: true, //Belangrijk: php needs to know wich file to load
    rollupOptions: {
      input: 'resources/js/main.ts',
    },
  },
  server: {
    proxy: {
      '/api' : 'htpps://onderwijsformulier.test'
    }
  }
})
