import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import fs from "node:fs";
import { fileURLToPath } from "node:url";
import dns from "node:dns";

dns.setDefaultResultOrder("ipv4first");

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./resources", import.meta.url)),
    },
  },
  build: {
    outDir: "public/build",
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        booking: "resources/js/booking/main.ts",
      },
    },
  },
  server: {
    host: "127.0.0.1",
    port: 5241,
    strictPort: true,
    https: {
      key: fs.readFileSync(
        "C:/wamp64/bin/apache/apache2.4.62.1/conf/ssl/onderwijs.testformulier.key",
      ),
      cert: fs.readFileSync(
        "C:/wamp64/bin/apache/apache2.4.62.1/conf/ssl/onderwijs.testformulier.crt",
      ),
    },
    hmr: {
      host: "onderwijsformulier.test",
      protocol: "wss",
      port: 5241,
    },
    cors: {
      origin: "https://onderwijsformulier.test",
    },
  },
});