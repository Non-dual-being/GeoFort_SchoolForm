import { createApp } from "vue";
import AdminDashboardApp from "./AdminDashboardApp.vue";
import { readAdminBootstrapData } from "./bootstrap/adminBootstrap";
import { adminRouter } from "./router";

export function mountAdminDashboard(): void {
  const mountElement = document.querySelector<HTMLElement>("#admin-app");
  if (!mountElement) return;

  const bootstrapData = readAdminBootstrapData();
  createApp(AdminDashboardApp, { bootstrapData }).use(adminRouter).mount(mountElement);
}
