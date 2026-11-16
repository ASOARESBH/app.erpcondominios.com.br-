<?php
declare(strict_types=1);

namespace ERP\Database;

class PgsqlMysqliResult
{
    public int $num_rows;
    public int $field_count;
    private int $cursor = 0;
    private bool $freed = false;

    public function __construct(private array $rows, int $fieldCount = 0)
    {
        $this->num_rows = count($rows);
        $this->field_count = $fieldCount > 0 ? $fieldCount : (isset($rows[0]) ? count($rows[0]) : 0);
    }

    public function fetch_assoc(): array|null
    {
        if ($this->freed || !isset($this->rows[$this->cursor])) {
            return null;
        }
        return self::associativeValues($this->rows[$this->cursor++]);
    }

    public function fetch_row(): array|null
    {
        if ($this->freed || !isset($this->rows[$this->cursor])) {
            return null;
        }
        return self::numericValues($this->rows[$this->cursor++]);
    }

    public function fetch_array(int $mode = MYSQLI_BOTH): array|null
    {
        if ($mode === MYSQLI_ASSOC) {
            return $this->fetch_assoc();
        }
        if ($mode === MYSQLI_NUM) {
            return $this->fetch_row();
        }
        if ($mode !== MYSQLI_BOTH || $this->freed || !isset($this->rows[$this->cursor])) {
            return null;
        }

        $row = $this->rows[$this->cursor++];
        $result = self::associativeValues($row);
        foreach (self::numericValues($row) as $index => $value) {
            $result[$index] = $value;
        }
        return $result;
    }

    public function fetch_all(int $mode = MYSQLI_NUM): array
    {
        if ($this->freed) {
            return [];
        }
        $rows = [];
        while (($row = $this->fetch_array($mode)) !== null) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function data_seek(int $offset): bool
    {
        if ($this->freed || $offset < 0 || $offset >= $this->num_rows) {
            return false;
        }
        $this->cursor = $offset;
        return true;
    }

    public function free(): void
    {
        $this->rows = [];
        $this->cursor = 0;
        $this->freed = true;
    }

    public function free_result(): void
    {
        $this->free();
    }

    public function allRows(): array
    {
        return $this->freed ? [] : $this->rows;
    }

    private static function numericValues(array $row): array
    {
        $numeric = [];
        foreach ($row as $key => $value) {
            if (is_int($key)) {
                $numeric[$key] = $value;
            }
        }
        if ($numeric === []) {
            return array_values($row);
        }
        ksort($numeric);
        return array_values($numeric);
    }

    private static function associativeValues(array $row): array
    {
        $associative = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $associative[$key] = $value;
            }
        }
        return $associative !== [] || $row === [] ? $associative : $row;
    }
}
