<?php
declare(strict_types=1);

namespace GeoFort\Data\Dashboard;

final readonly class DashboardBootstrapData
{
    /**
     * @param list<array{message: string, type: string, autoDismiss: bool}> $flashMessages
     */
    public function __construct(
        public int $userId,
        public string $userName,
        public string $userEmail,
        public string $userRole,
        public ?string $previousLoginAt,
        public string $logoutCsrfToken,
        public string $bookingStatusCsrfToken,
        public string $bookingAttendanceCsrfToken,
        public string $bookingCateringCsrfToken,
        public string $bookingVisitDateCsrfToken,
        public string $bookingProgramCsrfToken,
        public string $bookingProgramConfigurationCsrfToken,
        public array $flashMessages,
        public string $publicBookingUrl,
        public string $dashboardTitle,
        public string $appEnvironment,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user' => [
                'id' => $this->userId,
                'name' => $this->userName,
                'email' => $this->userEmail,
                'role' => $this->userRole,
                'previousLoginAt' => $this->previousLoginAt,
            ],
            'logoutCsrfToken' => $this->logoutCsrfToken,
            'bookingStatusCsrfToken' => $this->bookingStatusCsrfToken,
            'bookingAttendanceCsrfToken' => $this->bookingAttendanceCsrfToken,
            'bookingCateringCsrfToken' => $this->bookingCateringCsrfToken,
            'bookingVisitDateCsrfToken' => $this->bookingVisitDateCsrfToken,
            'bookingProgramCsrfToken' => $this->bookingProgramCsrfToken,
            'bookingProgramConfigurationCsrfToken' => $this->bookingProgramConfigurationCsrfToken,
            'flashMessages' => $this->flashMessages,
            'publicBookingUrl' => $this->publicBookingUrl,
            'dashboardTitle' => $this->dashboardTitle,
            'environment' => $this->appEnvironment,
        ];
    }
}
