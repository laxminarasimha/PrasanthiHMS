<?php

namespace App\Workforce\Http;

use App\Workforce\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Small things every workforce controller needs. */
trait Helpers
{
    /**
     * Checks that a record named by its id belongs to this branch (the branch wall is already in the query), and
     * returns it. A record of another hospital is "not found", never an error that confirms it exists.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T|null
     */
    protected function owned(string $model, mixed $id, string $field): ?Model
    {
        if ($id === null || $id === '') {
            return null;
        }
        $found = $model::query()->find($id);
        if (! $found) {
            throw ValidationException::withMessages([$field => 'Choose one from the list.']);
        }

        return $found;
    }

    /** A member of staff as shown in lists: never their private details or their pay. */
    protected function brief(Employee $e): array
    {
        return [
            'id' => $e->id, 'employee_no' => $e->employee_no, 'name' => $e->name, 'designation' => $e->designation, 'status' => $e->status,
            'department' => $e->relationLoaded('department') && $e->department ? ['id' => $e->department->id, 'name' => $e->department->name] : null,
        ];
    }

    /**
     * A file of rows to download, readable by Excel in any language.
     *
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    protected function csv(string $name, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                // A cell that starts like a formula is given a leading quote, so a spreadsheet shows it and does not run it.
                fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+\-@]/', $cell) ? "'".$cell : $cell, $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
