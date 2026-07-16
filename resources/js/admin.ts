import "flatpickr/dist/flatpickr.min.css";
import "../css/admin.css";
import { initializeServerFlashAutoDismiss } from "./admin/flash/initializeServerFlashAutoDismiss";
import { mountAdminDashboard } from "./admin/mountAdminDashboard";

initializeServerFlashAutoDismiss();
mountAdminDashboard();
