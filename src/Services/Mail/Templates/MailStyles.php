<?php
declare(strict_types=1);

namespace GeoFort\Services\Mail\Templates;

final class MailStyles
{
    public const COLOR_DARK_BLUE = '#081540';
    public const COLOR_DARK_LIGHT_BLUE = '#0f1f5b';
    public const COLOR_RED = '#D93134';
    public const COLOR_WHITE = '#FFFFFF';
    public const COLOR_TEXT = '#333333';
    public const COLOR_SOFT_GRAY = '#f7f7f7';
    public const COLOR_BORDER = '#d9deef';
    public const COLOR_LIGHT_BLUE = '#eef3ff';

    public const FONT_FAMILY = 'Arial, Helvetica, sans-serif';
    public const MAX_WIDTH = '700';

    public static function body(): string
    {
        return self::inline([
            'margin' => '0',
            'padding' => '0',
            'background-color' => self::COLOR_DARK_BLUE,
            'font-family' => self::FONT_FAMILY,
            'color' => self::COLOR_TEXT,
            '-webkit-text-size-adjust' => '100%',
            '-ms-text-size-adjust' => '100%',
        ]);
    }

    public static function outerTable(): string
    {
        return self::inline([
            'width' => '100%',
            'background-color' => self::COLOR_DARK_BLUE,
            'margin' => '0',
            'padding' => '20px 0',
            'border-collapse' => 'collapse',
        ]);
    }

    public static function containerTable(): string
    {
        return self::inline([
            'width' => '700px',
            'max-width' => '700px',
            'background-color' => self::COLOR_WHITE,
            'border-collapse' => 'collapse',
            'border' => '2px solid ' . self::COLOR_WHITE,
        ]);
    }

    public static function headerCell(): string
    {
        return self::inline([
            'background-color' => self::COLOR_DARK_BLUE,
            'color' => self::COLOR_WHITE,
            'padding' => '20px',
            'text-align' => 'center',
            'font-family' => self::FONT_FAMILY,
        ]);
    }

    public static function headerTitle(): string
    {
        return self::inline([
            'margin' => '0',
            'font-size' => '22px',
            'line-height' => '28px',
            'font-weight' => '700',
            'color' => self::COLOR_WHITE,
            'font-family' => self::FONT_FAMILY,
        ]);
    }

    public static function subHeaderCell(): string
    {
        return self::inline([
            'background-color' => self::COLOR_DARK_LIGHT_BLUE,
            'color' => self::COLOR_WHITE,
            'padding' => '12px 20px',
            'text-align' => 'center',
            'font-family' => self::FONT_FAMILY,
            'border-top' => '1px solid ' . self::COLOR_WHITE,
        ]);
    }

    public static function subHeaderTitle(): string
    {
        return self::inline([
            'margin' => '0',
            'font-size' => '16px',
            'line-height' => '22px',
            'font-weight' => '600',
            'color' => self::COLOR_WHITE,
            'font-family' => self::FONT_FAMILY,
        ]);
    }

    public static function contentCell(): string
    {
        return self::inline([
            'background-color' => self::COLOR_WHITE,
            'padding' => '24px 24px 16px 24px',
            'font-family' => self::FONT_FAMILY,
            'color' => self::COLOR_TEXT,
            'font-size' => '14px',
            'line-height' => '22px',
        ]);
    }

    public static function paragraph(): string
    {
        return self::inline([
            'margin' => '0 0 14px 0',
            'font-size' => '14px',
            'line-height' => '22px',
            'font-family' => self::FONT_FAMILY,
            'color' => self::COLOR_TEXT,
        ]);
    }

    public static function sectionTitle(): string
    {
        return self::inline([
            'margin' => '0',
            'font-size' => '16px',
            'line-height' => '22px',
            'font-weight' => '700',
            'color' => self::COLOR_DARK_BLUE,
            'font-family' => self::FONT_FAMILY,
        ]);
    }

    public static function infoTable(): string
    {
        return self::inline([
            'width' => '100%',
            'border-collapse' => 'collapse',
            'background-color' => self::COLOR_LIGHT_BLUE,
            'border' => '1px solid ' . self::COLOR_BORDER,
        ]);
    }

    public static function infoHeaderCell(): string
    {
        return self::inline([
            'background-color' => self::COLOR_DARK_BLUE,
            'color' => self::COLOR_WHITE,
            'padding' => '10px 14px',
            'font-family' => self::FONT_FAMILY,
            'font-size' => '15px',
            'line-height' => '20px',
            'font-weight' => '700',
            'text-align' => 'left',
        ]);
    }

    public static function labelCell(): string
    {
        return self::inline([
            'width' => '35%',
            'padding' => '10px 14px',
            'font-family' => self::FONT_FAMILY,
            'font-size' => '14px',
            'line-height' => '20px',
            'font-weight' => '700',
            'color' => self::COLOR_DARK_BLUE,
            'background-color' => self::COLOR_SOFT_GRAY,
            'border-top' => '1px solid ' . self::COLOR_BORDER,
            'vertical-align' => 'top',
        ]);
    }

    public static function valueCell(): string
    {
        return self::inline([
            'width' => '65%',
            'padding' => '10px 14px',
            'font-family' => self::FONT_FAMILY,
            'font-size' => '14px',
            'line-height' => '20px',
            'color' => self::COLOR_TEXT,
            'background-color' => self::COLOR_WHITE,
            'border-top' => '1px solid ' . self::COLOR_BORDER,
            'vertical-align' => 'top',
        ]);
    }

    public static function link(): string
    {
        return self::inline([
            'color' => self::COLOR_DARK_BLUE,
            'font-weight' => '700',
            'text-decoration' => 'underline',
        ]);
    }

    public static function redText(): string
    {
        return self::inline([
            'color' => self::COLOR_RED,
            'font-weight' => '700',
        ]);
    }

    public static function greetingCell(): string
    {
        return self::inline([
            'padding' => '16px 24px 24px 24px',
            'font-family' => self::FONT_FAMILY,
            'font-size' => '15px',
            'line-height' => '24px',
            'color' => self::COLOR_TEXT,
            'background-color' => self::COLOR_WHITE,
            'border-top' => '1px solid ' . self::COLOR_BORDER,
        ]);
    }

    public static function footerCell(): string
    {
        return self::inline([
            'background-color' => self::COLOR_DARK_BLUE,
            'color' => self::COLOR_WHITE,
            'padding' => '14px 20px',
            'text-align' => 'center',
            'font-family' => self::FONT_FAMILY,
            'font-size' => '12px',
            'line-height' => '18px',
        ]);
    }

    public static function footerLink(): string
    {
        return self::inline([
            'color' => self::COLOR_WHITE,
            'text-decoration' => 'underline',
            'font-weight' => '700',
        ]);
    }

    /**
     * @param array<string, string> $styles
     */
    private static function inline(array $styles): string
    {
        $css = '';

        foreach ($styles as $property => $value) {
            $css .= $property . ':' . $value . ';';
        }

        return $css;
    }
}