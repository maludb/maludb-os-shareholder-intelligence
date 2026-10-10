<?php
declare(strict_types=1);

/**
 * A filing's HTML as text with its paragraphs kept (edgar-and-finra-fetchers.md §2): scripts, styles and hidden inline-XBRL headers dropped;
 * a block element (p, div, tr, li, h1–h6, br, table) ends a line; a table cell is separated by " | "; entities decoded; spaces squeezed;
 * blank lines kept to one. DOMDocument with LIBXML_NONET and no network. EDGAR's .txt submissions pass through with their SGML tags stripped.
 */

function si_html_to_text(string $html): string
{
    if (!preg_match('~<(html|body|p|div|table|br)\b~i', $html)) {
        return si_text_tidy(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if (!mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    foreach (['script', 'style', 'head', 'noscript'] as $tag) {
        foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $n) {
            $n->parentNode?->removeChild($n);
        }
    }
    $xp = new DOMXPath($doc);
    foreach (iterator_to_array($xp->query('//*[contains(translate(@style, "DISPLAYNOE: ", "displaynoe:"), "display:none")] | //*[name()="ix:header"] | //*[local-name()="header" and starts-with(name(), "ix")]') ?: []) as $n) {
        $n->parentNode?->removeChild($n);
    }
    $out = '';
    $walk = static function (DOMNode $n) use (&$walk, &$out): void {
        if ($n instanceof DOMText) {
            $out .= $n->wholeText;
            return;
        }
        $name = strtolower($n->nodeName);
        $block = in_array($name, ['p', 'div', 'tr', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'section', 'article', 'blockquote', 'pre', 'center'], true);
        if ($name === 'br') {
            $out .= "\n";
            return;
        }
        if ($block) { $out .= "\n"; }
        foreach ($n->childNodes as $c) {
            $walk($c);
            if (in_array(strtolower($c->nodeName), ['td', 'th'], true)) { $out .= ' | '; }
        }
        if ($block) { $out .= "\n"; }
    };
    $walk($doc->documentElement ?? $doc);
    return si_text_tidy(html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** Spaces squeezed, a table row's trailing " | " dropped, blank lines kept to one. */
function si_text_tidy(string $t): string
{
    $t = str_replace(["\r\n", "\r", "\u{a0}"], ["\n", "\n", ' '], $t);
    $t = preg_replace('~[ \t]+~u', ' ', $t) ?? $t;
    $t = preg_replace('~( \| ?)+\n~', "\n", $t) ?? $t;
    $t = preg_replace('~ *\n *~', "\n", $t) ?? $t;
    $t = preg_replace('~\n{3,}~', "\n\n", $t) ?? $t;
    return trim($t);
}
