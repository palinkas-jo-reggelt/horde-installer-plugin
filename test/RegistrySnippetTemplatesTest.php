<?php

declare(strict_types=1);

namespace Horde\Composer;

use ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The registry snippets are PHP source generated from filesystem paths.
 * Paths that end in a backslash (Windows) or contain a single quote must not
 * break out of the generated string literals.
 */
class RegistrySnippetTemplatesTest extends TestCase
{
    private string $templateDir;
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->templateDir = dirname(__DIR__) . '/templates/registry';
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'registry-snippet-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    /**
     * @return array<string, array{string, string}> base dir, directory separator
     */
    public static function pathStyleProvider(): array
    {
        return [
            'posix' => ['/srv/horde', '/'],
            'windows' => ['X:\\xampp\\www\\horde6', '\\'],
            'windows with trailing backslash' => ['X:\\xampp\\www\\horde6\\', '\\'],
            'posix with single quote' => ["/home/o'brien/horde", '/'],
            'windows with single quote' => ["C:\\Users\\O'Brien\\horde", '\\'],
        ];
    }

    #[DataProvider('pathStyleProvider')]
    public function testAppSnippetsParseAndKeepPaths(string $baseDir, string $ds): void
    {
        $webDir = $baseDir . $ds . 'web';
        $webrootUri = '/';

        foreach (['horde' => '01-location-horde.php', 'imp' => '02-location-app.php'] as $appName => $template) {
            $appInVendorDir = $baseDir . $ds . 'vendor' . $ds . 'horde' . $ds . $appName . $ds;
            $code = (new TemplateRenderer())->render($this->templateDir . '/' . $template, [
                'appName' => $appName,
                'appInVendorDir' => $appInVendorDir,
                'webrootUri' => $webrootUri,
                'webDir' => $webDir,
                'ds' => $ds,
            ]);
            $registry = $this->evaluate($code);

            $this->assertSame($appInVendorDir, $registry->applications[$appName]['fileroot']);
            $this->assertSame($appInVendorDir . 'templates' . $ds, $registry->applications[$appName]['templates']);
            $this->assertSame($webDir . $ds . 'themes' . $ds . $appName . $ds, $registry->applications[$appName]['themesfs']);
            $this->assertSame($webDir . $ds . 'js' . $ds . $appName . $ds, $registry->applications[$appName]['jsfs']);
        }
    }

    #[DataProvider('pathStyleProvider')]
    public function testBaseSnippetParsesAndKeepsPaths(string $baseDir, string $ds): void
    {
        $webDir = $baseDir . $ds . 'web';
        $code = (new TemplateRenderer())->render($this->templateDir . '/00-horde.php', [
            'mode' => 'copy',
            'webrootUri' => '/',
            'appFileroot' => $webDir . $ds . 'horde',
            'appWebroot' => '/horde',
        ]);
        $this->assertParses($code);
        file_put_contents($this->tmpFile, $code);
        $vars = (static function (string $file): array {
            include $file;
            return compact('deployment_webroot', 'app_fileroot', 'app_webroot');
        })($this->tmpFile);

        $this->assertSame('/', $vars['deployment_webroot']);
        $this->assertSame($webDir . $ds . 'horde', $vars['app_fileroot']);
        $this->assertSame('/horde', $vars['app_webroot']);
    }

    public function testScriptAndThemeUrisHaveNoTrailingSlash(): void
    {
        // Core appends '/' + file to jsuri and themesuri itself.
        $registry = $this->evaluate((new TemplateRenderer())->render($this->templateDir . '/01-location-horde.php', [
            'appInVendorDir' => '/srv/horde/vendor/horde/horde/',
            'webrootUri' => '/',
            'webDir' => '/srv/horde/web',
            'ds' => '/',
        ]));
        $this->assertSame('/js/horde', $registry->applications['horde']['jsuri']);
        $this->assertSame('/themes/horde', $registry->applications['horde']['themesuri']);
    }

    private function assertParses(string $code): void
    {
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            $this->fail('Generated registry snippet is not valid PHP: ' . $e->getMessage() . "\n" . $code);
        }
        $this->addToAssertionCount(1);
    }

    private function evaluate(string $code): object
    {
        $this->assertParses($code);
        file_put_contents($this->tmpFile, $code);
        $registry = new class () {
            public array $applications = [];
        };
        (function (string $file): void {
            include $file;
        })->call($registry, $this->tmpFile);

        return $registry;
    }
}
