<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\Format\MarkdownBOM;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use PHPUnit\Framework\TestCase;

/**
 * Protects license notices while deduplicating presentation-only differences in Markdown.
 */
class MarkdownBOMTest extends TestCase
{
    /**
     * Equivalent formatting must share a reference and retain the first original license text.
     *
     * @return void
     */
    public function testCaseAndWhitespaceVariantsShareOneOriginalText() : void
    {
        $text = "MIT License\n\nCopyright (c) 2026 Example\nPermission is hereby granted.";
        $variant = " \r\nmit LICENSE\r\nCOPYRIGHT\t(c)   2026 example\r\npermission is HEREBY granted.\t";
        $packages = [
            new BOMPackage(['name' => 'example/first', 'license' => ['MIT'], 'license_text' => ['MIT' => $text]]),
            new BOMPackage(['name' => 'example/second', 'license' => ['MIT'], 'license_text' => ['MIT' => $variant]])
        ];
        $exporter = new MarkdownBOM(new LicenseBOM());
        $markdown = $exporter->writeUniqueLicenseNames($packages) . $exporter->writeLicenseTextVariants($packages);
        self::assertSame(1, substr_count($markdown, '### MIT<sup>*1</sup>'));
        self::assertSame(3, substr_count($markdown, 'MIT<sup>*1</sup>'));
        self::assertStringNotContainsString('MIT<sup>*2</sup>', $markdown);
        self::assertStringContainsString($text, $markdown);
        self::assertStringNotContainsString($variant, $markdown);
        self::assertSame($variant, $packages[1]->getLicenseText('MIT'));
    }

    /**
     * Changes to rights holders, dates or obligations must never be silently discarded.
     *
     * @return void
     */
    public function testSubstantiveDifferencesKeepSeparateVariants() : void
    {
        $texts = [
            'Copyright (c) 2026 Example. Permission is granted.',
            'Copyright (c) 2026 Another. Permission is granted.',
            'Copyright (c) 2025 Example. Permission is granted.',
            'Copyright (c) 2026 Example. Permission is not granted.',
            'Copyright (c) 2026 Example. Permission is granted!'
        ];
        $packages = [];
        foreach ($texts as $index => $text) {
            $packages[] = new BOMPackage(['name' => 'example/package-' . $index, 'license' => ['MIT'], 'license_text' => ['MIT' => $text]]);
        }
        $exporter = new MarkdownBOM(new LicenseBOM());
        $markdown = $exporter->writeUniqueLicenseNames($packages) . $exporter->writeLicenseTextVariants($packages);
        foreach ($texts as $index => $text) {
            self::assertStringContainsString($text, $markdown);
            self::assertSame(2, substr_count($markdown, 'MIT<sup>*' . ($index + 1) . '</sup>'));
        }
    }

    /**
     * Selected licenses, not their position among alternatives, own variant numbering.
     *
     * @return void
     */
    public function testVariantsAreScopedToEffectiveLicenseAndCurrentExport() : void
    {
        $package = new BOMPackage(['name' => 'example/selected', 'license' => ['MIT', 'BSD-3-Clause'],
            'license_used' => 'BSD-3-Clause', 'license_text' => ['BSD-3-Clause' => 'Original terms']]);
        $exporter = new MarkdownBOM(new LicenseBOM());
        self::assertStringContainsString('### BSD-3-Clause<sup>*1</sup>', $exporter->toMarkdown([$package]));
        $package->setLicenseText('BSD-3-Clause', 'Changed terms');
        $markdown = $exporter->toMarkdown([$package]);
        self::assertStringContainsString('### BSD-3-Clause<sup>*1</sup>', $markdown);
        self::assertStringNotContainsString('Original terms', $markdown);
        self::assertStringNotContainsString('<sup>*2</sup>', $markdown);
        $other = new BOMPackage(['name' => 'example/other', 'license' => ['MIT'], 'license_text' => ['MIT' => 'Changed terms']]);
        $markdown = $exporter->toMarkdown([$package, $other]);
        self::assertStringContainsString('### MIT<sup>*1</sup>', $markdown);
        self::assertStringContainsString('### BSD-3-Clause<sup>*1</sup>', $markdown);
    }

    /**
     * Nonbreaking spaces are layout differences, but joining separate words changes the text.
     *
     * @return void
     */
    public function testUnicodeWhitespaceAndWordBoundaries() : void
    {
        $packages = [];
        foreach (["MIT\u{00A0}License\nPermission granted.", 'mit license permission granted.', 'MIT License Permissiongranted.'] as $index => $text) {
            $packages[] = new BOMPackage(['name' => 'example/package-' . $index, 'license' => ['MIT'], 'license_text' => ['MIT' => $text]]);
        }
        $exporter = new MarkdownBOM(new LicenseBOM());
        $markdown = $exporter->writeUniqueLicenseNames($packages) . $exporter->writeLicenseTextVariants($packages);
        self::assertSame(3, substr_count($markdown, 'MIT<sup>*1</sup>'));
        self::assertSame(2, substr_count($markdown, 'MIT<sup>*2</sup>'));
    }

    /**
     * Missing license text must not break numbering or an otherwise valid empty export.
     *
     * @return void
     */
    public function testMissingTextsAndEmptyInventory() : void
    {
        $exporter = new MarkdownBOM(new LicenseBOM());
        self::assertSame("# Licenses\n\n", $exporter->toMarkdown([]));
        $packages = [
            new BOMPackage(['name' => 'example/missing', 'license' => ['MIT']]),
            new BOMPackage(['name' => 'example/complete', 'license' => ['MIT'], 'license_text' => ['MIT' => 'MIT terms']])
        ];
        $markdown = $exporter->writeUniqueLicenseNames($packages) . $exporter->writeLicenseTextVariants($packages);
        self::assertStringContainsString('### MIT<sup>*1</sup>', $markdown);
        self::assertSame(2, substr_count($markdown, 'MIT<sup>*2</sup>'));
        self::assertStringContainsString('MIT terms', $markdown);
    }
}