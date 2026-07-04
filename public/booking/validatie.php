<?php
declare(strict_types=1);

use GeoFort\Database\Connector;
use GeoFort\Services\Booking\Submission\BookingSubmissionService;
use GeoFort\Services\Booking\Availability\BookingAvailabilityService;
use GeoFort\Services\Booking\Presentation\EducationSelectionSummaryFactory;
use GeoFort\Services\Http\Api\Booking\BookingFormHandler;
use GeoFort\Services\Http\ClientIp\ClientIpResolver;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Mail\BookingMailService;
use GeoFort\Services\Mail\MailConfig;
use GeoFort\Services\Mail\PhpMailerMailer;
use GeoFort\Services\Mail\Templates\BookingRequestMailTemplate;
use GeoFort\Services\Mail\Templates\MailLayout;
use GeoFort\Services\Mail\Templates\MailLinks;
use GeoFort\Services\Sql\FormSubmitLogService;
use GeoFort\Services\Sql\RequestService;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Services\Sql\EducationSelectionSqlService;
use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Validation\Validator;
use GeoFort\Validation\EducationSelectionValidator;
use GeoFort\Validation\ProgramSelectionValidator;
use GeoFort\Validation\ChoiceModuleSelectionValidator;
use GeoFort\Validation\StudentCountValidator;



$container = require_once __DIR__ . '/../../bootstrap.php';

$response = null;

try {
    $pdo = $container['db'][Connector::class];
    $urlProvider = $container['http'][EnvironmentBaseUrlProvider::class];

    $response = new JsonResponse($urlProvider);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'POST') {
        $response->methodNotAllowed()->send();
        return;
    }

    $ipResult = ClientIpResolver::getClientIp($_SERVER);

    if ($ipResult->hasError || $ipResult->ip === null) {
        $response
            ->serverError('Ongeldig verzoek', 400, false)
            ->send();
        return;
    }

    $baseUrl = rtrim((string) ($container['config']['base_url'] ?? ''), '/');
    $voorwaardenUrl = $baseUrl !== ''
        ? $baseUrl . '/booking/voorwaarden.php'
        : '';


    $validator = new Validator();
    $educationSelectionHelper = new EducationSelectionSummaryFactory();

    $mailConfig = new MailConfig(
        host: $container['mail']['mail_host'],
        port: (int) $container['mail']['mail_port'],
        username: $container['mail']['mail_planner_email_user'],
        password: $container['mail']['mail_planner_email_pwd'],
        fromEmail: $container['mail']['mail_planner_email_user'],
        fromName: 'GeoFort Onderwijs',
        plannerEmail: $container['mail']['mail_planner_email_user'],
        testReceiverEmail: $container['mail']['mail_receiver_email_user'],
        appEnv: $container['config']['app_env'],
        smtpDebug: (int) $container['mail']['mail_smtp_debug'],
    );

    $mailLinks = new MailLinks(
        baseUrl: $baseUrl,
        voorwaardenUrl: $voorwaardenUrl,
        onderwijsEmail: $container['mail']['mail_planner_email_user'],
        websiteUrl: 'https://www.geofort.nl',
    );

    $mailLayout = new MailLayout($mailLinks);
    $mailTemplate = new BookingRequestMailTemplate(
        layout:                             $mailLayout, 
        links:                              $mailLinks, 
        validator:                          $validator,
        educationSelectionSummaryFactory:   $educationSelectionHelper

    );


    
    $mailer = new PhpMailerMailer($mailConfig);

    $bookingMailService = new BookingMailService(
        mailer: $mailer,
        config: $mailConfig,
        template: $mailTemplate,
    );

    
    $formSubmitLogSqlService = new FormSubmitLogService($pdo);
    $disabledDatesSqlService = new DisabledDatesSqlService($pdo);
    $requestService = new RequestService($pdo);
    $educationSelectionSqlService = new EducationSelectionSqlService($pdo);
    $bookingCalendarSqlService = new BookingCalendarSqlService($pdo);

    $bookingSubmissionService = new BookingSubmissionService(
        pdo: $pdo,
        submitSqlLogService: $formSubmitLogSqlService,
        requestService: $requestService,
        bookingMailService: $bookingMailService,
        educationSelectionSqlService: $educationSelectionSqlService,
    );

    $bookingAvailableService = new BookingAvailabilityService(
        disabledDatesSql: $disabledDatesSqlService,
        calendarSql: $bookingCalendarSqlService
    );

    $educationSelectionValidator = new EducationSelectionValidator();
    $programSelectionValidator = new ProgramSelectionValidator();
    $choiceModuleSelectionValidator = new ChoiceModuleSelectionValidator();
    $studentCountValidator = new StudentCountValidator();
    
    
    $handler = new BookingFormHandler(
        response: $response,
        validator: $validator,
        educationSelectionValidator: $educationSelectionValidator,
        formSubmitSqlLogService: $formSubmitLogSqlService,
        bookingAvailabilityService: $bookingAvailableService,
        bookingSubmissionService: $bookingSubmissionService,
        programSelectionValidator: $programSelectionValidator,
        choiceModuleSelectionValidator: $choiceModuleSelectionValidator,
        studentCountValidator: $studentCountValidator,
        ip: $ipResult->ip,
        cooldownSeconds: (int) ($container['config']['app_cooldown'] ?? 30),
    );

    $handler->handle($_POST);
} catch (Throwable $e) {
    error_log('Booking controller error: ' . $e->getMessage());

    if ($response instanceof JsonResponse) {
        $response
            ->serverError('Kritieke fout', 500, false)
            ->send();
        return;
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'ok' => false,
        'message' => 'Kritieke fout',
    ]);
}