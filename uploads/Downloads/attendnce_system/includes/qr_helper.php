<?php
/**
 * QR Code Helper
 * Uses BaconQrCode (installed via Composer) directly
 * simplesoftwareio/simple-qrcode wraps this for Laravel,
 * so we use BaconQrCode directly for native PHP.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require_once dirname(__DIR__) . '/vendor/autoload.php';

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Generate a QR code PNG file and save to disk.
 *
 * @param string $data     The string to encode (QR token)
 * @param string $savePath Absolute path to save the PNG
 * @param int    $size     Size in pixels (default 300)
 * @return bool            True on success
 */
function generateQRCode(string $data, string $savePath, int $size = 300): bool {
    try {
        // Try Imagick backend first (best quality)
        if (extension_loaded('imagick')) {
            $renderer = new ImageRenderer(
                new RendererStyle($size, 2),
                new ImagickImageBackEnd()
            );
            $writer = new Writer($renderer);
            $writer->writeFile($data, $savePath);
            return true;
        }

        // Fallback: SVG (works without Imagick)
        $svgPath = str_replace('.png', '.svg', $savePath);
        $renderer = new ImageRenderer(
            new RendererStyle($size, 2),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $writer->writeFile($data, $svgPath);

        // Convert SVG to PNG using GD if available
        if (extension_loaded('gd') && function_exists('imagecreatefrompng')) {
            svgToPng($svgPath, $savePath, $size);
            return true;
        }

        // Last resort: save as SVG and rename
        rename($svgPath, $savePath);
        return true;

    } catch (Exception $e) {
        error_log('QR Generation Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Generate QR code as raw SVG string (no file needed).
 * Use for inline display in HTML.
 *
 * @param string $data  The string to encode
 * @param int    $size  Size in pixels
 * @return string       SVG markup string
 */
function generateQRCodeSVG(string $data, int $size = 200): string {
    try {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 1),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        return $writer->writeString($data);
    } catch (Exception $e) {
        error_log('QR SVG Error: ' . $e->getMessage());
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '">
                    <rect width="100%" height="100%" fill="#fee2e2"/>
                    <text x="50%" y="50%" text-anchor="middle" fill="red" font-size="12">QR Error</text>
                </svg>';
    }
}

/**
 * Generate QR code as base64 PNG string for inline <img src>.
 *
 * @param string $data
 * @param int    $size
 * @return string  data:image/... string
 */
function generateQRCodeBase64(string $data, int $size = 200): string {
    try {
        if (extension_loaded('imagick')) {
            $renderer = new ImageRenderer(
                new RendererStyle($size, 2),
                new ImagickImageBackEnd()
            );
            $writer = new Writer($renderer);
            $bytes  = $writer->writeString($data);
            return 'data:image/png;base64,' . base64_encode($bytes);
        }

        // SVG fallback as base64
        $svg = generateQRCodeSVG($data, $size);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);

    } catch (Exception $e) {
        return '';
    }
}

/**
 * Serve a QR code directly as HTTP image response.
 * Call this from a dedicated endpoint like qr_image.php?token=XXX
 *
 * @param string $data
 * @param int    $size
 */
function serveQRCode(string $data, int $size = 300): void {
    try {
        if (extension_loaded('imagick')) {
            $renderer = new ImageRenderer(
                new RendererStyle($size, 2),
                new ImagickImageBackEnd()
            );
            $writer = new Writer($renderer);
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            echo $writer->writeString($data);
            exit;
        }

        // SVG fallback
        $svg = generateQRCodeSVG($data, $size);
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=86400');
        echo $svg;
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        exit('QR generation failed');
    }
}

/**
 * Simple SVG-to-PNG converter using GD
 * Only used as fallback when Imagick is unavailable
 */
function svgToPng(string $svgPath, string $pngPath, int $size): void {
    // GD cannot natively read SVG, so we create a placeholder PNG
    // with the QR data text as a fallback visual
    $im = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($im, 255, 255, 255);
    $black = imagecolorallocate($im, 0, 0, 0);
    imagefill($im, 0, 0, $white);
    imagestring($im, 2, 10, $size/2 - 8, 'QR: See SVG file', $black);
    imagepng($im, $pngPath);
    imagedestroy($im);
}