<?php declare(strict_types=1);

namespace ZanBaldwin\HttpPreload;

use Symfony\Component\WebLink\Link;

class LinkParser
{
    protected const LINK_HEADER_REGEX = '/\<(?<href>.*?)\>[^,]*;\s*rel=\"(?<rel>.*?)\".*?,?/';

    /** @return \Symfony\Component\WebLink\Link[] */
    public function parse(string $headerValue): array
    {
        $matches = [];
        preg_match_all(static::LINK_HEADER_REGEX, $headerValue, $matches, \PREG_SET_ORDER);
        return array_map(function (array $match) {
            $link = new Link(null, $match['href']);
            foreach (preg_split('/\s+/', $match['rel'], -1, \PREG_SPLIT_NO_EMPTY) as $rel) {
                $link = $link->withRel($rel);
            }
            return $link;
        }, $matches);
    }
}
