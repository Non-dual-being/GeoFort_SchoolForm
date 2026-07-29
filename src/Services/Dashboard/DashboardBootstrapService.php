<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard;

use GeoFort\Data\Dashboard\DashboardBootstrapData;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Support\FlashStore;

final class DashboardBootstrapService
{
    public function __construct(
        private readonly CsrfTokenService $csrfTokenService,
        private readonly string $appEnvironment,
    ) {}

    public function build(): DashboardBootstrapData
    {
        return new DashboardBootstrapData(
            userId: (int) $_SESSION['user_id'],
            userName: (string) $_SESSION['user_name'],
            userEmail: (string) $_SESSION['user_email'],
            userRole: (string) $_SESSION['user_role'],
            previousLoginAt: is_string($_SESSION['previous_login_at'] ?? null)
                ? $_SESSION['previous_login_at']
                : null,
            logoutCsrfToken: $this->csrfTokenService->getOrCreate('logout'),
            bookingStatusCsrfToken: $this->csrfTokenService->getOrCreate('update-booking-status'),
            bookingAttendanceCsrfToken: $this->csrfTokenService->getOrCreate('update-booking-attendance'),
            bookingCateringCsrfToken: $this->csrfTokenService->getOrCreate('update-booking-catering'),
            bookingVisitDateCsrfToken: $this->csrfTokenService->getOrCreate('update-booking-visit-date'),
            bookingProgramCsrfToken: $this->csrfTokenService->getOrCreate('update-booking-program'),
            bookingProgramConfigurationCsrfToken: $this->csrfTokenService->getOrCreate('update-booking-program-configuration'),
            bookingSchoolContactCsrfToken: $this->csrfTokenService->getOrCreate('update-booking-school-contact'),
            bookingCjpCsrfToken: $this->csrfTokenService->getOrCreate('update-booking-cjp'),
            calendarDateManagementCsrfToken: $this->csrfTokenService->getOrCreate('manage-calendar-date'),
            flashMessages: $this->normalizeFlashMessages(FlashStore::pull('dashboard')),
            publicBookingUrl: '/',
            dashboardTitle: 'GeoFort Onderwijs Dashboard',
            appEnvironment: $this->appEnvironment,
        );
    }

    /**
     * @param array<array-key, mixed> $messages
     * @return list<array{message: string, type: string, autoDismiss: bool}>
     */
    private function normalizeFlashMessages(array $messages): array
    {
        $normalized = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $type = in_array($message['type'] ?? null, ['success', 'error', 'info'], true)
                ? (string) $message['type']
                : 'info';
            $text = $message['message'] ?? '';

            if (!is_string($text) || $text === '') {
                continue;
            }

            $normalized[] = [
                'message' => $text,
                'type' => $type,
                'autoDismiss' => $type === 'success',
            ];
        }

        return $normalized;
    }
}
