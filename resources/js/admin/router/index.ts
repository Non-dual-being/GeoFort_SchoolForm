import { createRouter, createWebHashHistory } from "vue-router";
import DashboardOverviewView from "../views/DashboardOverviewView.vue";
import NotFoundView from "../views/NotFoundView.vue";
import DashboardBookingsView from "../views/DashboardBookingsView.vue";
import DashboardBookingDetailView from "../views/DashboardBookingDetailView.vue";
import DashboardCalendarView from "../views/DashboardCalendarView.vue";
import DashboardBookingExportView from "../views/DashboardBookingExportView.vue";
import DashboardBookingRevenueView from "../views/DashboardBookingRevenueView.vue";
import DashboardRostersView from "../views/DashboardRostersView.vue";
import DashboardRosterDetailView from "../views/DashboardRosterDetailView.vue";

export const adminRouter = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: "/", name: "overview", component: DashboardOverviewView },
    { path: "/aanvragen", name: "bookings", component: DashboardBookingsView },
    { path: "/aanvragen/:id", name: "booking-detail", component: DashboardBookingDetailView },
    { path: "/agenda", name: "calendar", component: DashboardCalendarView },
    { path: "/roosters", name: "rosters", component: DashboardRostersView },
    { path: "/roosters/:id", name: "roster-detail", component: DashboardRosterDetailView },
    { path: "/export", name: "booking-export", component: DashboardBookingExportView },
    { path: "/omzet", name: "booking-revenue", component: DashboardBookingRevenueView },
    {
      path: "/analytics",
      name: "booking-analytics",
      component: () => import("../views/DashboardBookingAnalyticsView.vue"),
    },
    { path: "/:pathMatch(.*)*", name: "not-found", component: NotFoundView },
  ],
});
