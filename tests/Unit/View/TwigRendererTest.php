<?php

declare(strict_types=1);

namespace TripBuilder\Tests\Unit\View;

use PHPUnit\Framework\TestCase;
use TripBuilder\Config;
use TripBuilder\View\TwigRenderer;

final class TwigRendererTest extends TestCase
{
    private TwigRenderer $renderer;

    protected function setUp(): void
    {
        $_ENV['AWS_CLOUDFRONT'] = 'cdn.example.test';
        new Config('common');

        $this->renderer = new TwigRenderer();
    }

    public function testRendersFragment(): void
    {
        $html = $this->renderer->render('search/redirect.html.twig', [
            'image_url'     => '//cdn.example.test/gif.gif',
            'search_params' => 'from=YUL',
        ]);

        self::assertStringContainsString('window.location.replace', $html);
        self::assertStringContainsString('src="//cdn.example.test/gif.gif"', $html);
    }

    public function testAutoEscapesHtmlContext(): void
    {
        $html = $this->renderer->render('search/redirect.html.twig', [
            'image_url'     => 'x"><script>alert(1)</script>',
            'search_params' => '',
        ]);

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testEscapesJavascriptContext(): void
    {
        $html = $this->renderer->render('search/redirect.html.twig', [
            'image_url'     => '',
            'search_params' => 'a="b"&c=<d>',
        ]);

        // \uXXXX sequences, built here without a literal backslash in the source.
        $u = chr(92) . 'u';

        // The raw quote must not survive unescaped into the JS string; escape('js')
        // emits \uXXXX sequences instead.
        self::assertStringNotContainsString('?a="b"', $html);
        self::assertStringContainsString($u . '0022', $html); // escaped "
        self::assertStringContainsString($u . '0026', $html); // escaped &
    }

    public function testRegisteredFunctionsAndEscapingInRealTemplate(): void
    {
        $airline = (object) [
            'code'  => 'AC',
            'title' => '<b>Air & Co</b>',
            'phone' => '123',
            'url'   => 'https://example.test',
        ];

        $html = $this->renderer->render('airlines/card.html.twig', ['airline' => $airline]);

        // cdn() + config() build the logo URL.
        self::assertStringContainsString('//cdn.example.test/images/suppliers/AC.png', $html);
        // The airline title is auto-escaped in both text and attribute context.
        self::assertStringContainsString('&lt;b&gt;Air &amp; Co&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>Air & Co</b>', $html);
    }
}
