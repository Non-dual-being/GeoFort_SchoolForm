<?php
declare(strict_types=1);
namespace GeoFort\Validation;

final class FieldValidationException extends \DomainException 
{
    public function __construct(
        private readonly string $field,
        string $message,
    ){
        parent::__construct($message);
    }

    public function getField(): string {
        return $this->field;
    }
}
?>