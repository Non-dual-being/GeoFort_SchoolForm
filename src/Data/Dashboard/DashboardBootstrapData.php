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
        public string $rosterPlanCsrfToken,
        public string $rosterSessionCsrfToken,
        public string $rosterGenerationCsrfToken,
        public string $bookingSchoolContactCsrfToken,
        public string $bookingCjpCsrfToken,
        public string $calendarDateManagementCsrfToken,
        public string $legacyPriceAcceptanceCsrfToken,
        public string $capacityTargetCsrfToken,
        public array $flashMessages,
        public string $publicBookingUrl,
        public string $dashboardTitle,
        public string $appEnvironment,
        public string $rosterFinalizeCsrfToken = '',
        public string $rosterReopenCsrfToken = '',
        public string $rosterDocumentCsrfToken = '',
        public string $rosterStaffCreateCsrfToken = '',
        public string $rosterStaffUpdateCsrfToken = '',
        public string $rosterStaffImpactCsrfToken = '',
        public string $rosterStaffSelectionCsrfToken = '',
        public string $rosterResetSessionsCsrfToken = '',
        public string $rosterStaffDeactivateCsrfToken = '',
        public string $rosterJointOptimizeCsrfToken = '',
        public string $rosterJointLifecycleCsrfToken = '',
        public string $rosterPairLinkCsrfToken = '',
        public string $rosterPairArchiveCsrfToken = '',
        public string $rosterDayLessonsCsrfToken = '',
        public string $rosterBookingRefreshCsrfToken = '',
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
            'rosterPlanCsrfToken' => $this->rosterPlanCsrfToken,
            'rosterSessionCsrfToken' => $this->rosterSessionCsrfToken,
            'rosterGenerationCsrfToken' => $this->rosterGenerationCsrfToken,
            'rosterJointOptimizeCsrfToken' => $this->rosterJointOptimizeCsrfToken,
            'rosterJointLifecycleCsrfToken' => $this->rosterJointLifecycleCsrfToken,
            'rosterPairLinkCsrfToken' => $this->rosterPairLinkCsrfToken,
            'rosterPairArchiveCsrfToken' => $this->rosterPairArchiveCsrfToken,
            'rosterBookingRefreshCsrfToken' => $this->rosterBookingRefreshCsrfToken,
            'rosterDayLessonsCsrfToken' => $this->rosterDayLessonsCsrfToken,
            'rosterFinalizeCsrfToken' => $this->rosterFinalizeCsrfToken,
            'rosterReopenCsrfToken' => $this->rosterReopenCsrfToken,
            'rosterDocumentCsrfToken' => $this->rosterDocumentCsrfToken,
            'rosterStaffCreateCsrfToken' => $this->rosterStaffCreateCsrfToken,
            'rosterStaffUpdateCsrfToken' => $this->rosterStaffUpdateCsrfToken,
            'rosterStaffImpactCsrfToken' => $this->rosterStaffImpactCsrfToken,
            'rosterStaffDeactivateCsrfToken' => $this->rosterStaffDeactivateCsrfToken,
            'rosterStaffSelectionCsrfToken' => $this->rosterStaffSelectionCsrfToken,
            'rosterResetSessionsCsrfToken' => $this->rosterResetSessionsCsrfToken,
            'bookingSchoolContactCsrfToken' => $this->bookingSchoolContactCsrfToken,
            'bookingCjpCsrfToken' => $this->bookingCjpCsrfToken,
            'calendarDateManagementCsrfToken' => $this->calendarDateManagementCsrfToken,
            'legacyPriceAcceptanceCsrfToken' => $this->legacyPriceAcceptanceCsrfToken,
            'capacityTargetCsrfToken' => $this->capacityTargetCsrfToken,
            'flashMessages' => $this->flashMessages,
            'publicBookingUrl' => $this->publicBookingUrl,
            'dashboardTitle' => $this->dashboardTitle,
            'environment' => $this->appEnvironment,
        ];
    }
}
