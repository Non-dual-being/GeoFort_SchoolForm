import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import fs from 'node:fs';

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    manifest: true, //Belangrijk: php needs to know wich file to load
    rollupOptions: {
      input: {
          booking: "resources/js/booking/main.ts",
      },
    },
  },
  server: {
    host: "onderwijsformulier.test",
    port: 5173,
    strictPort: true,
    https: {
      key: fs.readFileSync(
        "C:/wamp64/bin/apache/apache2.4.62.1/conf/ssl/onderwijs.testformulier.key"
      ),
      cert: fs.readFileSync(
        "C:/wamp64/bin/apache/apache2.4.62.1/conf/ssl/onderwijs.testformulier.crt"
      ),
    },
      // HMR werkt via websockets; bij https moet dat wss zijn
    hmr: {
      host: "onderwijsformulier.test",
      protocol: "wss",
      port: 5173,
    },
    cors: {
      origin: "https://onderwijsformulier.test",
    }
  }
})
