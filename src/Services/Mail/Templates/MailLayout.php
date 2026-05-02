<?php
declare(strict_types=1);

namespace GeoFort\Services\Mail\Templates;

final readonly class MailLayout
{
    public function __construct(
        private MailLinks $links,
    ) {}

    public function render(
        string $title,
        string $subtitle,
        string $contentHtml,
    ): string {
        $safeTitle = $this->escape($title);
        $safeSubtitle = $this->escape($subtitle);

        return '<!doctype html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $safeTitle . '</title>
</head>
<body style="' . MailStyles::body() . '">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="' . MailStyles::outerTable() . '">
        <tr>
            <td align="center">
                <table role="presentation" width="700" cellpadding="0" cellspacing="0" border="0" style="' . MailStyles::containerTable() . '">
                    <tr>
                        <td style="' . MailStyles::headerCell() . '">
                            <h1 style="' . MailStyles::headerTitle() . '">' . $safeTitle . '</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="' . MailStyles::subHeaderCell() . '">
                            <h2 style="' . MailStyles::subHeaderTitle() . '">' . $safeSubtitle . '</h2>
                        </td>
                    </tr>
                    <tr>
                        <td style="' . MailStyles::contentCell() . '">
                            ' . $contentHtml . '
                        </td>
                    </tr>
                    <tr>
                        <td style="' . MailStyles::greetingCell() . '">
                            Met vriendelijke groet,<br>
                            <strong>Team Onderwijs - 
                                <a href="' . $this->escapeAttr($this->links->websiteUrl) . '" style="' . MailStyles::link() . '">
                                    <span style="color:' . MailStyles::COLOR_TEXT . ';">Geo</span><span style="color:' . MailStyles::COLOR_RED . ';">Fort</span>
                                </a>
                            </strong>
                        </td>
                    </tr>
                    <tr>
                        <td style="' . MailStyles::footerCell() . '">
                            GeoFort Onderwijs<br>
                            <a href="mailto:' . $this->escapeAttr($this->links->onderwijsEmail) . '" style="' . MailStyles::footerLink() . '">' . $this->escape($this->links->onderwijsEmail) . '</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}