<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;

/** Metadata snapshot for one reconciliation run, never shared across Octane requests. */
final class FinancialReconciliationSchema
{
    private array $tables = [];
    private array $columns = [];

    public function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        $columns = $this->columns[$table] ??= Schema::getColumnListing($table);
        return in_array($column, $columns, true);
    }
}
