<?php

declare(strict_types=1);

namespace BitrixTelegram\Helpers;

class BBCodeConverter
{
    private const REPLACEMENTS = [
        '/\[b\](.*?)\[\/b\]/is' => '<b>$1</b>',
        '/\[i\](.*?)\[\/i\]/is' => '<i>$1</i>',
        '/\[u\](.*?)\[\/u\]/is' => '<u>$1</u>',
        '/\[s\](.*?)\[\/s\]/is' => '<s>$1</s>',
        '/\[br\]/is' => "\n",
        '/\[code\](.*?)\[\/code\]/is' => '<code>$1</code>',
        '/\[pre\](.*?)\[\/pre\]/is' => '<pre>$1</pre>',
        '/\[url\](.*?)\[\/url\]/is' => '<a href="$1">$1</a>',
        '/\[url=(.*?)\](.*?)\[\/url\]/is' => '<a href="$1">$2</a>',
        '/\[size=(.*?)\](.*?)\[\/size\]/is' => '$2',
        '/\[color=(.*?)\](.*?)\[\/color\]/is' => '$2',
        '/\[quote\](.*?)\[\/quote\]/is' => '« $1 »',
        '/\[quote=(.*?)\](.*?)\[\/quote\]/is' => '« $2 » — $1',
    ];

    public function toHtml(string $text): string
    {
        $result = preg_replace(
            array_keys(self::REPLACEMENTS),
            array_values(self::REPLACEMENTS),
            $text
        );
        
        return html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function toBBCode(string $html): string
    {
        $replacements = [
            '/<b>(.*?)<\/b>/is' => '[b]$1[/b]',
            '/<i>(.*?)<\/i>/is' => '[i]$1[/i]',
            '/<u>(.*?)<\/u>/is' => '[u]$1[/u]',
            '/<s>(.*?)<\/s>/is' => '[s]$1[/s]',
            '/<code>(.*?)<\/code>/is' => '[code]$1[/code]',
            '/<pre>(.*?)<\/pre>/is' => '[pre]$1[/pre]',
            '/<a href="(.*?)">(.*?)<\/a>/is' => '[url=$1]$2[/url]',
        ];
        
        return preg_replace(
            array_keys($replacements),
            array_values($replacements),
            $html
        );
    }
}