<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Response;


final class JsonResponse extends Response
{
    // Let op: als een child-constructor EXACT hetzelfde doet als de parent
    // (alleen parent::__construct aanroepen), mag je hem in PHP volledig weglaten.
    // PHP roept dan automatisch de constructor van de parent aan.

    /**
     * return $this allows method chaining
     * 
     * (new JsonResponse($globalbaseprovider))
     *          ->method1()
     *          ->method2()
     * 
     */

    public function ok(): static
    {
        $this->status = 200;
        $this->headers = $this->defaultHeaders();
        $this->payload = ['ok' => true];

        return $this;
    }

    public function validationError(array $fieldErrors): static
    {
        $this->status = 422;
        $this->headers = $this->defaultHeaders();
        $this->payload = [
            'ok'          => false,
            'type'        => 'validation',
            'fieldErrors' => $fieldErrors
        ];

        return $this;
    }

    public function serverError(string $message, int $code = 500, bool $logError = false): static
    {
        $this->status = $code >= 400 ? $code : 500;
        $this->headers = $this->defaultHeaders();

        // Hier zou je $message kunnen loggen naar een bestand of error tracking systeem
        // error_log("Critical GeoFort Error: " . $message);

        // We sturen de message NIET naar de frontend, exact volgens jouw TS type ApiServerError
        $this->payload = [
            'ok'   => false,
            'type' => 'server',
            'code' => $this->status,
        ];

        if ($logError) $this->logError($message);

        return $this;
    }

    public function rateLimited(int $retryAfter): static 
    {
        $this->status = 429;
        $this->headers = $this->defaultHeaders();
        $this->headers['Retry-After'] = (string) max(1, $retryAfter);
        $this->payload = [
            'ok'         => false,
            'type'       => 'rate-limit',
            'retryAfter' => max(1, $retryAfter),
        ];
        return $this;
        /**
         * 429 too many requests
         */

    }

    public function methodNotAllowed(): static 
    {
        $this->status = 405;
        $this->headers = $this->defaultHeaders();
        $this->payload = [
            'ok'    => false,
            'type'  => 'server',
            'code'  => 405,
        ];

        return $this;

        /**
         * 405 method not allowed
        */
    }

    public function json(array $payload, int $status = 200): static {
        $this->status =  $status;
        $this->headers = $this->defaultHeaders();
        $this->payload = $payload;

        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);

        if (!headers_sent()) {
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v, true);
            }
        }

        echo json_encode(
            $this->payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private function defaultHeaders(): array
    {
        return [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ];
    }

    private function logError(string $error = ''): void {
        if ($error === '') $error = 'unkown error';
        error_log("Critical server error: $error");
    }
}