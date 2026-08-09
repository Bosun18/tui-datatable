<?php

declare(strict_types=1);

/*
 * Wiring the table into a framework console command, Laravel flavour.
 *
 * This file is a sketch, not a runnable script: it needs illuminate/console,
 * which this package does not depend on. Copy the class into
 * app/Console/Commands/ and adjust the query. For symfony/console the shape is
 * the same — build the widget in execute(), call run(), return an exit code.
 *
 * Three things worth copying: load the data in one query, since N+1 hurts a
 * console tool as much as a web page; keep raw values in the cells and format
 * on the way out, so sorting compares dates and not their text; and subscribe
 * to events before the first sortBy(), because a sort that happens earlier
 * fires its event into an empty listener list.
 */

namespace App\Console\Commands;

use Bosun18\TuiDataTable\Align;
use Bosun18\TuiDataTable\Column;
use Bosun18\TuiDataTable\Event\FilterChangeEvent;
use Bosun18\TuiDataTable\Event\RowSelectEvent;
use Bosun18\TuiDataTable\SortDirection;
use Bosun18\TuiDataTable\TableWidget;
use Illuminate\Console\Command;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

final class BrowseOrders extends Command
{
    protected $signature = 'orders:browse';

    protected $description = 'Browse recent orders in a table';

    /** Statuses sort by their place in the lifecycle, not alphabetically. */
    private const array STATUS_WEIGHT = ['new' => 0, 'paid' => 1, 'shipped' => 2, 'cancelled' => 3];

    public function handle(): int
    {
        $rows = $this->rows();

        if ([] === $rows) {
            $this->warn('No orders yet.');

            return self::SUCCESS;
        }

        $table = new TableWidget(
            columns: $this->columns(),
            rows: $rows,
            maxVisible: 15,
            keybindings: new Keybindings(['cancel' => [Key::ESCAPE, 'ctrl+c', 'q']]),
        );

        $status = new TextWidget();
        $filter = new InputWidget()->setPrompt('  Filter: ');
        $hint = new TextWidget('  arrows: move   s: sort   /: filter   Enter: open   q: quit', truncate: true);

        $tui = new Tui();
        $tui->addStyleSheet(TableWidget::defaultStyleSheet());

        // The widget reports the match count through FilterChangeEvent only, so
        // a caller that wants it on screen has to keep it.
        $total = \count($rows);
        $matches = $total;
        $refresh = static function () use ($status, &$matches, $total): void {
            $status->setText(\sprintf('  %d of %d orders', $matches, $total));
        };

        $selected = null;
        $table
            ->onFilterChange(static function (FilterChangeEvent $event) use (&$matches, $refresh): void {
                $matches = $event->matchCount;
                $refresh();
            })
            ->onRowSelect(static function (RowSelectEvent $event) use (&$selected, $tui): void {
                $selected = $event->row;
                $tui->stop();
            })
            ->onCancel(static fn () => $tui->stop())
            // '/' opens the filter input; every other key goes to the table.
            ->onInput(static function (string $data) use ($tui, $filter): bool {
                if ('/' !== $data) {
                    return false;
                }

                $tui->setFocus($filter);

                return true;
            });

        $filter
            // Filtering as you type reads better than waiting for Enter.
            ->onChange(static function (ChangeEvent $event) use ($table): void {
                $event->isBlank() ? $table->clearFilter() : $table->setFilter($event->getValue());
            })
            ->onSubmit(static fn () => $tui->setFocus($table))
            ->onCancel(static function () use ($tui, $table, $filter): void {
                $filter->setValue('');
                $table->clearFilter();
                $tui->setFocus($table);
            });

        // After the subscriptions, so the status line picks this up on its own.
        $table->sortBy('placed_at', SortDirection::Desc);
        $refresh();

        $tui->add($status)->add($table)->add($filter)->add($hint);
        $tui->setFocus($table);

        // Blocks here; control returns once stop() is called and the terminal
        // has been restored.
        $tui->run();

        if (null === $selected) {
            return self::SUCCESS;
        }

        // Back in framework land: print, dispatch a job, open a URL.
        $this->info(\sprintf('Order %s selected.', $selected['number']));

        return self::SUCCESS;
    }

    /**
     * One query with the relations the columns need. Dates go in as timestamps
     * and become readable in the formatter, which keeps both sorting and the
     * substring filter working on what you see.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return Order::query()
            ->with(['customer:id,name'])
            ->latest('placed_at')
            ->limit(500)
            ->get()
            ->map(static fn (Order $order): array => [
                'number' => $order->number,
                // Anything coming from a database may carry control bytes, and
                // cell text is rendered as-is.
                'customer' => StringUtils::stripControlBytes($order->customer?->name ?? '—'),
                'placed_at' => $order->placed_at->getTimestamp(),
                'total' => $order->total_cents,
                'status' => $order->status,
            ])
            ->all();
    }

    /**
     * @return list<Column>
     */
    private function columns(): array
    {
        return [
            new Column('number', 'Order', width: 12),
            new Column('customer', 'Customer'),
            new Column(
                'placed_at',
                'Placed',
                width: 12,
                formatter: static fn (mixed $value): string => date('d.m.Y', (int) $value),
            ),
            new Column(
                'total',
                'Total',
                width: 12,
                align: Align::Right,
                formatter: static fn (mixed $value): string => number_format((int) $value / 100, 2).' EUR',
            ),
            new Column(
                'status',
                'Status',
                width: 10,
                comparator: static fn (mixed $left, mixed $right): int => (self::STATUS_WEIGHT[$left] ?? \PHP_INT_MAX) <=> (self::STATUS_WEIGHT[$right] ?? \PHP_INT_MAX),
            ),
        ];
    }
}
