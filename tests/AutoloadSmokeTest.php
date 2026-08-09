<?php

declare(strict_types=1);

namespace Bosun18\TuiDataTable\Tests;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * Keeps PHPUnit and CI meaningful while src/ is still empty.
 *
 * Delete this once real tests cover the widget.
 */
#[CoversNothing]
final class AutoloadSmokeTest extends TestCase
{
    public function testUpstreamClassesAndPackagePrefixesAreAutoloadable(): void
    {
        self::assertTrue(class_exists(AbstractWidget::class));
        self::assertTrue(class_exists(SelectListWidget::class));

        $prefixes = [];
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $prefixes = [...$prefixes, ...array_keys($loader->getPrefixesPsr4())];
        }

        self::assertContains('Bosun18\\TuiDataTable\\', $prefixes);
        self::assertContains('Bosun18\\TuiDataTable\\Tests\\', $prefixes);
    }
}
