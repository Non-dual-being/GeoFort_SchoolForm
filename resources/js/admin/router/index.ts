import { createRouter, createWebHashHistory } from "vue-router";
import DashboardOverviewView from "../views/DashboardOverviewView.vue";
import NotFoundView from "../views/NotFoundView.vue";

export const adminRouter = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: "/", name: "overview", component: DashboardOverviewView },
    { path: "/:pathMatch(.*)*", name: "not-found", component: NotFoundView },
  ],
});
