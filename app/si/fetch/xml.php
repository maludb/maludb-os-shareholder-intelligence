<?php
declare(strict_types=1);

/**
 * The SEC's XML read safely (edgar-and-finra-fetchers.md §4): no network (LIBXML_NONET), no entity substitution, no DTD loaded — a document
 * that declares an entity is refused before it is parsed (the XXE door shut twice); the namespaces stripped so the ownership forms' elements
 * read by their local names. Slice 4 reads an XML primary document as text; slice 5 parses the forms.
 *   si_xml_load(string $xml): SimpleXMLElement   (throws SiXmlError)
 *   si_xml_text(string $xml): string             the text content, one element's text a line
 */

final class SiXmlError extends RuntimeException
{
}

function si_xml_load(string $xml): SimpleXMLElement
{
    if (preg_match('~<!ENTITY|<!DOCTYPE[^>]*\[~i', $xml)) {
        throw new SiXmlError('The XML declares an entity: refused (no external or internal entities are read).');
    }
    $xml = preg_replace('~\sxmlns(:\w+)?="[^"]*"~', '', $xml) ?? $xml;          // the namespaces stripped
    $xml = preg_replace('~<(/?)\w+:(\w)~', '<$1$2', $xml) ?? $xml;               // prefixed elements read by local name
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($x === false) {
        throw new SiXmlError('The XML could not be read.');
    }
    return $x;
}

function si_xml_text(string $xml): string
{
    $x = si_xml_load($xml);
    $lines = [];
    $walk = static function (SimpleXMLElement $e, string $path) use (&$walk, &$lines): void {
        $kids = $e->children();
        if (count($kids) === 0) {
            $t = trim((string) $e);
            if ($t !== '') {
                $lines[] = $path . ': ' . $t;
            }
            return;
        }
        foreach ($kids as $k) {
            $walk($k, in_array($k->getName(), ['value', 'footnoteId'], true) ? $e->getName() . ' ' . $k->getName() : $k->getName());   // <transactionShares><value> reads "transactionShares value"
        }
    };
    $walk($x, $x->getName());
    return implode("\n", $lines);
}
