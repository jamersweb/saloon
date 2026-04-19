<?php

namespace App\Support;

use App\Models\FinanceSetting;
use App\Models\TaxInvoice;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;
use Illuminate\Support\Facades\View;

final class TaxReceiptPdfView
{
    /** Must be ASCII-only so Ar-PHP utf8Glyphs() never touches it. */
    private const LOGO_PLACEHOLDER = '___RECEIPT_LOGO_BLOCK___';

    /**
     * View data for the thermal tax receipt PDF (DomPDF).
     */
    public static function payload(TaxInvoice $invoice): array
    {
        $invoice->load(['items', 'customer', 'payments']);
        $settings = FinanceSetting::current();

        return [
            'settings' => $settings,
            'invoice' => $invoice,
            'logo_placeholder' => self::LOGO_PLACEHOLDER,
        ];
    }

    /**
     * DomPDF does not shape Arabic; Ar-PHP converts to joined presentation forms.
     * The logo is injected after shaping so long base64 data URIs are never processed by utf8Glyphs().
     */
    public static function shapedHtml(TaxInvoice $invoice): string
    {
        $html = View::make('finance.tax_receipt_pdf', self::payload($invoice))->render();

        $arabic = new Arabic;
        $shaped = $arabic->utf8Glyphs($html, 500_000, false, false);

        return str_replace(self::LOGO_PLACEHOLDER, self::logoImgHtml(), $shaped);
    }

    /**
     * Compact PNG (scaled when GD is available) or inline SVG — works without file:// URLs.
     */
    public static function logoImgHtml(): string
    {
        $path = public_path('images/vina-logo.png');
        if (is_file($path) && is_readable($path)) {
            $binary = self::compactLogoPngBinary($path);
            if ($binary !== null && $binary !== '') {
                return '<img class="receipt-logo" src="data:image/png;base64,'.base64_encode($binary).'" alt="" />';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="72" viewBox="0 0 240 72" class="receipt-logo">'
            .'<rect width="240" height="72" rx="8" fill="#312e81"/>'
            .'<text x="120" y="46" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="26" font-weight="bold" fill="#ffffff">Vina</text>'
            .'</svg>';
    }

    private static function compactLogoPngBinary(string $path): ?string
    {
        if (! function_exists('imagecreatefrompng')) {
            $raw = file_get_contents($path);

            return $raw !== false ? $raw : null;
        }

        $src = @imagecreatefrompng($path);
        if ($src === false) {
            $raw = file_get_contents($path);

            return $raw !== false ? $raw : null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 1 || $h < 1) {
            imagedestroy($src);

            return null;
        }

        /* Larger cap so PNG matches receipt CSS (wide logo + up to ~78px tall). */
        $maxW = 200;
        $maxH = 96;
        $scale = min($maxW / $w, $maxH / $h, 1.0);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            imagedestroy($src);

            $raw = file_get_contents($path);

            return $raw !== false ? $raw : null;
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        ob_start();
        imagepng($dst, null, 6);
        $out = ob_get_clean();
        imagedestroy($dst);

        return $out !== false && $out !== '' ? $out : null;
    }

    public static function makePdf(TaxInvoice $invoice): PdfWrapper
    {
        $chroot = realpath(base_path()) ?: base_path();

        return Pdf::loadHTML(self::shapedHtml($invoice), 'UTF-8')
            ->setPaper([0, 0, 226.77, 841.89])
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('defaultMediaType', 'print')
            ->setOption('isRemoteEnabled', true)
            ->setOption('chroot', $chroot);
    }
}
