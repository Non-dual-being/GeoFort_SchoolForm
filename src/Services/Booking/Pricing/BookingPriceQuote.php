<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

final readonly class BookingPriceQuote
{
    /**
     * @param list<BookingPriceLine> $visitLines
     * @param list<BookingPriceLine> $foodLines
     */
    public function __construct(
        public array $visitLines,
        public array $foodLines,
        public int $studentCount,
        public int $supervisorCount,
        public int $freeSupervisors,
        public int $paidSupervisors,
        public string $calculationState,
        public string $pricingVersion,
        public string $currencyCode,
        public int $pricePerVisitorInclVatCents,
        public int $visitAmountInclVatCents,
        public int $visitAmountExclVatCents,
        public int $cateringAmountInclVatCents,
        public int $cateringAmountExclVatCents,
        public int $totalAmountInclVatCents,
        public int $totalAmountExclVatCents,
        public int $vatAmountCents,
        public int $vatBasisPoints,
    ) {}

    public function toArray(): array
    {
        return [
            'calculationState' => $this->calculationState,
            'pricingVersion' => $this->pricingVersion,
            'currencyCode' => $this->currencyCode,
            'vatBasisPoints' => $this->vatBasisPoints,
            'visit' => [
                'studentCount' => $this->studentCount,
                'supervisorCount' => $this->supervisorCount,
                'freeSupervisors' => $this->freeSupervisors,
                'paidSupervisors' => $this->paidSupervisors,
                'pricePerVisitorInclVatCents' => $this->pricePerVisitorInclVatCents,
                'lines' => array_map(
                    static fn (BookingPriceLine $line): array => $line->toArray(),
                    $this->visitLines,
                ),
                'amountInclVatCents' => $this->visitAmountInclVatCents,
                'amountExclVatCents' => $this->visitAmountExclVatCents,
            ],
            'foodAndDrink' => [
                'lines' => array_map(
                    static fn (BookingPriceLine $line): array => $line->toArray(),
                    $this->foodLines,
                ),
                'amountInclVatCents' => $this->cateringAmountInclVatCents,
                'amountExclVatCents' => $this->cateringAmountExclVatCents,
            ],
            'total' => [
                'amountInclVatCents' => $this->totalAmountInclVatCents,
                'amountExclVatCents' => $this->totalAmountExclVatCents,
                'vatAmountCents' => $this->vatAmountCents,
            ],
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $visit=$data['visit']??null;$food=$data['foodAndDrink']??null;$total=$data['total']??null;
        if(!is_array($visit)||!is_array($food)||!is_array($total)||!is_array($visit['lines']??null)||!is_array($food['lines']??null))throw new \InvalidArgumentException('Ongeldige opgeslagen prijsdetails.');
        $integer=static function(array $source,string $key):int{$value=$source[$key]??null;if(!is_int($value))throw new \InvalidArgumentException('Ongeldige opgeslagen centswaarde.');return$value;};
        foreach(['calculationState','pricingVersion','currencyCode'] as $key)if(!is_string($data[$key]??null))throw new \InvalidArgumentException('Ongeldige opgeslagen prijsmetadata.');
        return new self(
            array_map(static fn(array $line):BookingPriceLine=>BookingPriceLine::fromArray($line),$visit['lines']),
            array_map(static fn(array $line):BookingPriceLine=>BookingPriceLine::fromArray($line),$food['lines']),
            $integer($visit,'studentCount'),$integer($visit,'supervisorCount'),$integer($visit,'freeSupervisors'),$integer($visit,'paidSupervisors'),
            $data['calculationState'],$data['pricingVersion'],$data['currencyCode'],$integer($visit,'pricePerVisitorInclVatCents'),
            $integer($visit,'amountInclVatCents'),$integer($visit,'amountExclVatCents'),$integer($food,'amountInclVatCents'),$integer($food,'amountExclVatCents'),
            $integer($total,'amountInclVatCents'),$integer($total,'amountExclVatCents'),$integer($total,'vatAmountCents'),$integer($data,'vatBasisPoints'),
        );
    }
}
