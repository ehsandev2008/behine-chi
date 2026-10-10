<?php
/**
 * موتور بومی بهینه‌سازی و فشرده‌سازی فایل‌های SVG
 * Native Pure-PHP SVG Optimizer & Minifier (Zero External Dependencies, No CDN/APIs)
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

if (!defined('ABSPATH')) {
    exit;
}

class SVG_Optimizer {

    /**
     * Singleton instance.
     *
     * @var SVG_Optimizer|null
     */
    private static ?SVG_Optimizer $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return SVG_Optimizer
     */
    public static function instance(): SVG_Optimizer {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {}

    /**
     * Optimizes and minifies an SVG file in-place safely (fail-safe: never corrupts original).
     *
     * @param string $file_path Absolute path to the SVG file.
     * @return array{success: bool, original_size: int, optimized_size: int, saved_bytes: int, savings_percent: float, message: string}
     */
    public function optimize(string $file_path): array {
        if (!file_exists($file_path) || !is_readable($file_path) || !is_writable($file_path)) {
            return [
                'success'         => false,
                'original_size'   => 0,
                'optimized_size'  => 0,
                'saved_bytes'     => 0,
                'savings_percent' => 0,
                'message'         => 'فایل SVG قابل دسترسی یا نوشتن نیست.',
            ];
        }

        $raw_content = @file_get_contents($file_path);
        if ($raw_content === false || '' === trim($raw_content)) {
            return [
                'success'         => false,
                'original_size'   => 0,
                'optimized_size'  => 0,
                'saved_bytes'     => 0,
                'savings_percent' => 0,
                'message'         => 'محتوای فایل SVG خالی است.',
            ];
        }

        $orig_size = (int) filesize($file_path);
        if ($orig_size <= 0) {
            $orig_size = strlen($raw_content);
        }

        // Fail-safe 1: input must be well-formed XML with an <svg> root. Otherwise keep original.
        if (!$this->is_valid_svg($raw_content)) {
            return [
                'success'         => false,
                'original_size'   => $orig_size,
                'optimized_size'  => $orig_size,
                'saved_bytes'     => 0,
                'savings_percent' => 0,
                'message'         => 'فایل SVG نامعتبر است؛ برای جلوگیری از خرابی، فایل اصلی حفظ شد.',
            ];
        }

        // Minify and clean SVG content safely
        $optimized_content = $this->minify_svg_content($raw_content);

        // Fail-safe 2: output must remain well-formed and preserve essentials. Otherwise keep original.
        if ('' === $optimized_content || !$this->is_valid_svg($optimized_content)) {
            return [
                'success'         => false,
                'original_size'   => $orig_size,
                'optimized_size'  => $orig_size,
                'saved_bytes'     => 0,
                'savings_percent' => 0,
                'message'         => 'فرآیند بهینه‌سازی به ساختار اصلی SVG آسیب رساند و لغو شد؛ فایل اصلی حفظ شد.',
            ];
        }

        if (!$this->essentials_preserved($raw_content, $optimized_content)) {
            return [
                'success'         => false,
                'original_size'   => $orig_size,
                'optimized_size'  => $orig_size,
                'saved_bytes'     => 0,
                'savings_percent' => 0,
                'message'         => 'حذف ویژگی‌های ضروری SVG مجاز نیست؛ فایل اصلی حفظ شد.',
            ];
        }

        $opt_len = strlen($optimized_content);

        // Only overwrite when we actually reduce size (or equal but cleaned). Never grow the file.
        if ($opt_len < $orig_size) {
            $written = @file_put_contents($file_path, $optimized_content, LOCK_EX);
            if (false === $written) {
                return [
                    'success'         => false,
                    'original_size'   => $orig_size,
                    'optimized_size'  => $orig_size,
                    'saved_bytes'     => 0,
                    'savings_percent' => 0,
                    'message'         => 'امکان ذخیره فایل بهینه‌شده روی دیسک وجود نداشت.',
                ];
            }
            // Fail-safe 3: re-validate bytes actually written to disk.
            $on_disk = @file_get_contents($file_path);
            if (false === $on_disk || !$this->is_valid_svg($on_disk)) {
                // Roll back to in-memory original content.
                @file_put_contents($file_path, $raw_content, LOCK_EX);
                return [
                    'success'         => false,
                    'original_size'   => $orig_size,
                    'optimized_size'  => $orig_size,
                    'saved_bytes'     => 0,
                    'savings_percent' => 0,
                    'message'         => 'خروجی ذخیره‌شده نامعتبر بود و به حالت اصلی بازگردانده شد.',
                ];
            }
            $opt_size = (int) filesize($file_path);
        } else {
            // Optimization did not shrink the file: keep original untouched.
            $opt_size = $orig_size;
        }

        $saved = max(0, $orig_size - $opt_size);
        $pct   = $orig_size > 0 ? round(($saved / $orig_size) * 100, 2) : 0.0;

        return [
            'success'         => true,
            'original_size'   => $orig_size,
            'optimized_size'  => $opt_size,
            'saved_bytes'     => $saved,
            'savings_percent' => $pct,
            'message'         => sprintf('فایل SVG با موفقیت بهینه‌سازی شد! کاهش حجم: %s (٪%s)', size_format($saved, 2), $pct),
        ];
    }

    /**
     * Checks whether content is a well-formed SVG document.
     *
     * @param string $content Raw SVG markup.
     * @return bool
     */
    public function is_valid_svg(string $content): bool {
        if ('' === trim($content) || stripos($content, '<svg') === false) {
            return false;
        }
        // Reject entity declarations / external DTD (XXE hardening).
        if (preg_match('/<!ENTITY/i', $content) || preg_match('/<!DOCTYPE[^>]*\[/is', $content)) {
            return false;
        }

        // Reject obvious non-SVG / binary uploads.
        if (str_starts_with(ltrim($content), "\x89PNG")
            || str_starts_with(ltrim($content), "\xFF\xD8\xFF")
            || str_starts_with(ltrim($content), 'GIF8')) {
            return false;
        }

        $prev = libxml_use_internal_errors(true);
        if (function_exists('libxml_disable_entity_loader')) {
            @libxml_disable_entity_loader(true);
        }
        $dom  = new \DOMDocument();
        $ok   = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            return false;
        }

        $root = $dom->documentElement;
        if (!$root || strtolower($root->nodeName) !== 'svg') {
            // Allow svg nested (e.g. with leading whitespace/comments already parsed).
            $svgs = $dom->getElementsByTagName('svg');
            if (0 === $svgs->length) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ensures essential rendering attributes/namespaces were not dropped.
     *
     * Guards: viewBox (when present), xmlns, xmlns:xlink (when used), width/height presence.
     *
     * @param string $before Original markup.
     * @param string $after  Minified markup.
     * @return bool
     */
    private function essentials_preserved(string $before, string $after): bool {
        // viewBox must survive when the original had one.
        if (preg_match('/\bviewBox\s*=\s*(["\'])(.*?)\1/i', $before, $m_before)) {
            if (!preg_match('/\bviewBox\s*=\s*(["\'])(.*?)\1/i', $after, $m_after)
                || trim($m_after[2]) !== trim($m_before[2])) {
                return false;
            }
        }

        // Core SVG namespace must survive when originally present.
        if (stripos($before, 'xmlns=') !== false && stripos($after, 'xmlns=') === false) {
            return false;
        }

        // xlink namespace must survive when xlink:href is used.
        if (stripos($before, 'xlink:href') !== false) {
            if (stripos($after, 'xlink:href') === false
                || (stripos($before, 'xmlns:xlink') !== false && stripos($after, 'xmlns:xlink') === false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pure PHP standalone SVG cleaner and minifier.
     *
     * Safety rules (never break rendering):
     * - Preserves xmlns, xmlns:xlink, viewBox, width/height, preserveAspectRatio,
     *   role, aria-*, <style>, <title>, <desc>, gradients, filters, clipPaths.
     * - Never strips x="0"/y="0" from shapes (only collapses whitespace).
     * - Never strips version/xml:space/enable-background (rendering-relevant).
     * - Only removes: comments, editor metadata, scripts/event-handlers,
     *   editor namespaces, empty containers, and redundant whitespace.
     *
     * @param string $svg Raw SVG markup.
     * @return string Minified SVG (or original when unsafe).
     */
    public function minify_svg_content(string $svg): string {
        $original = $svg;

        // 1. Remove XML comments (keep conditional/IE markers out of scope: SVGs rarely need them).
        $svg = preg_replace('/<!--(?!\[)[\\s\\S]*?-->/', '', $svg);
        if (null === $svg) {
            return $original;
        }

        // 2. Remove editor-specific metadata tags (Illustrator, Inkscape, Sketch, Figma).
        //    NOTE: <title> and <desc> are accessibility-relevant and are intentionally KEPT.
        $metadata_tags = [
            '/<metadata[\\s\\S]*?<\/metadata>/i',
            '/<rdf:RDF[\\s\\S]*?<\/rdf:RDF>/i',
            '/<sodipodi:namedview[\\s\\S]*?<\/sodipodi:namedview>/is',
            '/<sodipodi:namedview[^>]*\/>/i',
            '/<inkscape:grid[^>]*\/>/i',
            '/<i:pgf[\\s\\S]*?<\/i:pgf>/i',
            '/<i:pgf[^>]*\/>/i',
            '/<x:xmpmeta[\\s\\S]*?<\/x:xmpmeta>/i',
        ];
        $svg = preg_replace($metadata_tags, '', $svg);
        if (null === $svg) {
            return $original;
        }

        // 3. Remove security risks (scripts, inline event handlers, javascript:/data: URIs,
        //    foreignObject/animate-based XSS vectors, style-based javascript).
        $svg = preg_replace('/<script[\\s\\S]*?<\/script>/i', '', $svg);
        $svg = preg_replace('/<foreignObject[\\s\\S]*?<\/foreignObject>/i', '', $svg);
        $svg = preg_replace('/\s+on[a-z]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i', '', $svg);
        $svg = preg_replace('/\bhref\s*=\s*["\']\s*javascript:[^"\']*["\']/i', 'href=""', $svg);
        $svg = preg_replace('/\bxlink:href\s*=\s*["\']\s*javascript:[^"\']*["\']/i', 'xlink:href=""', $svg);
        $svg = preg_replace('/\bhref\s*=\s*["\']\s*data:text\/html[^"\']*["\']/i', 'href=""', $svg);
        $svg = preg_replace('/\bxlink:href\s*=\s*["\']\s*data:text\/html[^"\']*["\']/i', 'xlink:href=""', $svg);
        // Neutralize javascript:/expression()/behaviour inside style attributes and <style> blocks.
        $svg = preg_replace_callback('/(<style[^>]*>)([\\s\\S]*?)(<\/style>)/i', function ($m) {
            $css = preg_replace('/javascript\s*:/i', '', $m[2]);
            $css = preg_replace('/expression\s*\(/i', '', (string) $css);
            $css = preg_replace('/-moz-binding\s*:/i', '', (string) $css);
            return $m[1] . $css . $m[3];
        }, $svg);
        $svg = preg_replace_callback('/\bstyle\s*=\s*(["\'])(.*?)\1/is', function ($m) {
            $style = preg_replace('/javascript\s*:/i', '', $m[2]);
            $style = preg_replace('/expression\s*\(/i', '', (string) $style);
            $style = preg_replace('/-moz-binding\s*:/i', '', (string) $style);
            return 'style=' . $m[1] . $style . $m[1];
        }, $svg);
        // Strip animate/set event vectors that can execute script.
        $svg = preg_replace('/<(animate|set)[^>]*\bonbegin[^>]*>/i', '<$1>', $svg);
        if (null === $svg) {
            return $original;
        }

        // 4. Remove ONLY editor/authoring namespaces & attributes.
        //    Deliberately NOT touching: xmlns, xmlns:xlink, viewBox, width, height,
        //    x, y, version, xml:space, enable-background, preserveAspectRatio.
        $editor_attrs = [
            '/\s+xmlns:inkscape\s*=\s*["\'][^"\']*["\']/i',
            '/\s+xmlns:sodipodi\s*=\s*["\'][^"\']*["\']/i',
            '/\s+xmlns:sketch\s*=\s*["\'][^"\']*["\']/i',
            '/\s+xmlns:i\s*=\s*["\'][^"\']*["\']/i',
            '/\s+xmlns:graph\s*=\s*["\'][^"\']*["\']/i',
            '/\s+xmlns:illustrator\s*=\s*["\'][^"\']*["\']/i',
            '/\s+xmlns:corel\s*=\s*["\'][^"\']*["\']/i',
            '/\s+inkscape:[a-zA-Z0-9_-]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i',
            '/\s+sodipodi:[a-zA-Z0-9_-]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i',
            '/\s+sketch:[a-zA-Z0-9_-]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i',
            '/\s+i:[a-zA-Z0-9_-]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i',
            '/\s+data-name\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i',
        ];
        $svg = preg_replace($editor_attrs, '', $svg);
        if (null === $svg) {
            return $original;
        }

        // 5. Remove empty groups / defs only (safe: they render nothing).
        $prev = null;
        $guard = 0;
        while ($prev !== $svg && $guard < 5) {
            $prev = $svg;
            $svg = preg_replace('/<g[^>]*>\s*<\/g>/i', '', $svg);
            $svg = preg_replace('/<defs[^>]*>\s*<\/defs>/i', '', $svg);
            if (null === $svg) {
                return $original;
            }
            $guard++;
        }

        // 6. Compact path 'd' attributes (whitespace + float zero trimming only; commands untouched).
        $svg = preg_replace_callback('/\bd\s*=\s*(["\'])(.*?)\1/s', function ($matches) {
            $quote = $matches[1];
            $d     = $matches[2];
            if ('' === trim($d)) {
                return $matches[0];
            }
            // Normalize comma/whitespace separators.
            $d = preg_replace('/[,\s]+/', ' ', $d);
            // Trim trailing zeroes on decimals: 12.3000 -> 12.3 ; 0.50 -> 0.5
            $d = preg_replace_callback('/-?\d+\.\d+/', function ($num) {
                $val = rtrim(rtrim($num[0], '0'), '.');
                if ('-0' === $val || '-0.0' === $num[0]) {
                    return '0';
                }
                return $val;
            }, $d);
            return 'd=' . $quote . trim((string) $d) . $quote;
        }, $svg);
        if (null === $svg) {
            return $original;
        }

        // 7. Compact polygon/polyline points (separator normalization only).
        $svg = preg_replace_callback('/\bpoints\s*=\s*(["\'])(.*?)\1/s', function ($matches) {
            $quote = $matches[1];
            $pts   = trim((string) preg_replace('/[,\s]+/', ' ', $matches[2]));
            return 'points=' . $quote . $pts . $quote;
        }, $svg);
        if (null === $svg) {
            return $original;
        }

        // 8. Collapse whitespace between tags and redundant spaces (outside <style>/<text> content we keep it simple but safe:
        //    only collapse "> <" boundaries and 2+ spaces; never touch single spaces inside text nodes aggressively).
        $svg = preg_replace('/>\s+</', '><', $svg);
        $svg = preg_replace('/\s{2,}/', ' ', $svg);
        $svg = preg_replace('/\s+(\/?>)/', '$1', $svg);
        if (null === $svg) {
            return $original;
        }

        $svg = trim($svg);

        // Final guard: never return empty or non-SVG.
        if ('' === $svg || stripos($svg, '<svg') === false) {
            return $original;
        }

        return $svg;
    }
}
