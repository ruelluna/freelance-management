<?php

namespace App\Support;

use Dom\HTMLDocument;

class HtmlLinks
{
    public static function openInNewTab(string $html): string
    {
        if (trim($html) === '' || ! str_contains(strtolower($html), '<a')) {
            return $html;
        }

        $document = HTMLDocument::createFromString($html, LIBXML_HTML_NOIMPLIED | LIBXML_NOERROR);

        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            $rels = preg_split(
                '/\s+/',
                trim($anchor->getAttribute('rel').' noopener noreferrer'),
                -1,
                PREG_SPLIT_NO_EMPTY,
            );

            $anchor->setAttribute('target', '_blank');
            $anchor->setAttribute('rel', implode(' ', array_values(array_unique($rels))));
        }

        return $document->saveHTML();
    }
}
