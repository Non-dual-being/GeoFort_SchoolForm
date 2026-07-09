import fs from "node:fs";
import { fileURLToPath, URL } from "node:url";
import vue from "@vitejs/plugin-vue";
import { defineConfig, loadEnv } from "vite";

const sslKeyPath =
  "C:/wamp64/bin/apache/apache2.4.62.1/conf/ssl/onderwijs.testformulier.key";

const sslCertPath =
  "C:/wamp64/bin/apache/apache2.4.62.1/conf/ssl/onderwijs.testformulier.crt";

export default defineConfig(({ command, mode }) => {
  const env = loadEnv(mode, process.cwd(), "");

  const isServe = command === "serve";
  const hasLocalSslFiles =
    fs.existsSync(sslKeyPath) && fs.existsSync(sslCertPath);

  return {
    plugins: [vue()],

    resolve: {
      alias: {
        "@": fileURLToPath(new URL("./resources/js", import.meta.url)),
      },
    },

    server: {
      host: env.VITE_DEV_SERVER_HOST || "onderwijsformulier.test",
      port: Number(env.VITE_DEV_SERVER_PORT || 5241),
      strictPort: true,
      https:
        isServe && hasLocalSslFiles
          ? {
              key: fs.readFileSync(sslKeyPath),
              cert: fs.readFileSync(sslCertPath),
            }
          : undefined,
    },

    build: {
      manifest: true,
      outDir: "public/build",
      emptyOutDir: true,
      rollupOptions: {
        input: {
          booking: fileURLToPath(
            new URL("./resources/js/booking/main.ts", import.meta.url),
          ),
        },
      },
    },
  };
});