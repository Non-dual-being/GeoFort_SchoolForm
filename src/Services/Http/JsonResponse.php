<?php
declare(strict_types=1);
namespace GeoFort\Services\Http;

final class JsonResponse extends Response
{
    public function __construct(
        private readonly array $data,
        int $status = 200,
        array $headers = []
    ) {
        parent::__construct($status, $headers);
    }

    public function send(): void {
        http_response_code($this->status);
        header('Content-type: application/json; charset=utf-8');
        foreach ($this->headers as $k => $v) header($k . ': ' . $v);
        echo json_encode($this->data, JSON_UNESCAPED_UNICODE);
    }
}
?>