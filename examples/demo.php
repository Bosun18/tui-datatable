<?php

declare(strict_types=1);

use Bosun18\TuiDataTable\Align;
use Bosun18\TuiDataTable\Column;
use Bosun18\TuiDataTable\Event\FilterChangeEvent;
use Bosun18\TuiDataTable\Event\RowSelectEvent;
use Bosun18\TuiDataTable\Event\SortChangeEvent;
use Bosun18\TuiDataTable\SortDirection;
use Bosun18\TuiDataTable\TableWidget;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TextWidget;

require __DIR__.'/../vendor/autoload.php';

// Download counts are made up. This is a demo, not a Packagist mirror.
$rows = [
    ['name' => 'symfony/console', 'downloads' => 812_004_331, 'license' => 'MIT', 'php' => '>=8.2'],
    ['name' => 'symfony/tui', 'downloads' => 41_207, 'license' => 'MIT', 'php' => '>=8.4.1'],
    ['name' => 'psr/log', 'downloads' => 704_118_920, 'license' => 'MIT', 'php' => '>=8.0'],
    ['name' => 'monolog/monolog', 'downloads' => 553_802_114, 'license' => 'MIT', 'php' => '>=8.1'],
    ['name' => 'guzzlehttp/guzzle', 'downloads' => 498_330_712, 'license' => 'MIT', 'php' => '>=7.2.5'],
    ['name' => 'phpunit/phpunit', 'downloads' => 312_770_806, 'license' => 'BSD-3-Clause', 'php' => '>=8.4.1'],
    ['name' => 'doctrine/orm', 'downloads' => 201_449_608, 'license' => 'MIT', 'php' => '>=8.1'],
    ['name' => 'symfony/string', 'downloads' => 188_015_442, 'license' => 'MIT', 'php' => '>=8.2'],
    ['name' => 'nikic/php-parser', 'downloads' => 176_664_003, 'license' => 'BSD-3-Clause', 'php' => '>=7.4'],
    ['name' => 'twig/twig', 'downloads' => 143_998_257, 'license' => 'BSD-3-Clause', 'php' => '>=8.1'],
    ['name' => 'phpstan/phpstan', 'downloads' => 128_540_119, 'license' => 'MIT', 'php' => '>=7.4'],
    ['name' => 'friendsofphp/php-cs-fixer', 'downloads' => 96_112_508, 'license' => 'MIT', 'php' => '>=7.4'],
    ['name' => 'league/flysystem', 'downloads' => 89_663_401, 'license' => 'MIT', 'php' => '>=8.0.2'],
    ['name' => 'revolt/event-loop', 'downloads' => 44_206_915, 'license' => 'MIT', 'php' => '>=8.1'],
    ['name' => 'spatie/laravel-data', 'downloads' => 12_884_570, 'license' => 'MIT', 'php' => '>=8.2'],
];

$table = new TableWidget(
    columns: [
        new Column('name', 'Package'),
        new Column(
            'downloads',
            'Downloads',
            align: Align::Right,
            formatter: static fn (mixed $value): string => \is_int($value) ? number_format($value) : '',
        ),
        new Column('license', 'License', width: 14),
        new Column('php', 'PHP', width: 8, sortable: false),
    ],
    rows: $rows,
    maxVisible: 10,
    // 'q' quits as well, which is what everyone tries first in a TUI.
    keybindings: new Keybindings(['cancel' => [Key::ESCAPE, 'ctrl+c', 'q']]),
);

$status = new TextWidget('Move with the arrows, press Enter on a row.');
$hint = new TextWidget(
    '  arrows: move   PgUp/PgDn: page   s: sort   f: filter   Enter: select   q: quit',
    truncate: true,
);
$status->addStyleClass('demo-status');
$hint->addStyleClass('demo-hint');

$tui = new Tui();
$tui->addStyleSheet(TableWidget::defaultStyleSheet());
$tui->addStyleSheet(new StyleSheet([
    '.demo-status' => new Style()->withColor('cyan'),
    '.demo-hint' => new Style()->withColor('gray'),
]));

// There is no filter input widget yet, so 'f' toggles a canned one to show
// what setFilter() does.
$filtered = false;
$table->onInput(static function (string $data) use ($table, &$filtered): bool {
    if ('f' !== $data) {
        return false;
    }

    $filtered = !$filtered;
    $filtered ? $table->setFilter('symfony/') : $table->clearFilter();

    return true;
});

$table
    ->onRowSelect(static function (RowSelectEvent $event) use ($status): void {
        $status->setText(\sprintf(
            'Selected %s at row %d.',
            \is_string($event->row['name']) ? $event->row['name'] : '?',
            $event->index + 1,
        ));
    })
    ->onSortChange(static function (SortChangeEvent $event) use ($status): void {
        $status->setText(match ($event->direction) {
            SortDirection::Asc => \sprintf('Sorted by %s, smallest first.', $event->key),
            SortDirection::Desc => \sprintf('Sorted by %s, largest first.', $event->key),
            null => 'Sorting off, back to the original order.',
        });
    })
    ->onFilterChange(static function (FilterChangeEvent $event) use ($status, &$filtered): void {
        $status->setText($filtered
            ? \sprintf('Filter "symfony/" leaves %d rows.', $event->matchCount)
            : \sprintf('Filter cleared, %d rows.', $event->matchCount));
    })
    ->onCancel(static function (CancelEvent $event) use ($tui): void {
        $tui->stop();
    });

$tui->add($table)->add($status)->add($hint);
$tui->setFocus($table);
$tui->run();
