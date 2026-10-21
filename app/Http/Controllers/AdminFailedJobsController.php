<?php

namespace App\Http\Controllers;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminFailedJobsController extends Controller
{
    public function index()
    {
        $connection = $this->connection();

        if (! $connection->getSchemaBuilder()->hasTable('failed_jobs')) {
            $jobs = new LengthAwarePaginator(collect(), 0, 25);
        } else {
            $jobs = $connection->table('failed_jobs')
                ->select(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
                ->orderByDesc('failed_at')
                ->paginate(25)
                ->withQueryString();

            $jobs->getCollection()->transform(function ($job): object {
                $payload = json_decode($job->payload, true) ?: [];
                $exception = $this->redactSecrets((string) $job->exception);

                $job->job_name = $payload['displayName'] ?? $payload['job'] ?? 'Job necunoscut';
                $job->exception_summary = Str::limit(trim(strtok($exception, "\r\n") ?: 'Eroare fără detalii.'), 500);
                $job->exception_details = Str::limit($exception, 30000);

                // The serialized command can contain credentials; do not expose the raw payload.
                unset($job->payload);

                return $job;
            });
        }

        return view('admin.failed-jobs', ['failedJobs' => $jobs]);
    }

    public function count(): int
    {
        try {
            return $this->connection()->getSchemaBuilder()->hasTable('failed_jobs')
                ? $this->connection()->table('failed_jobs')->count()
                : 0;
        } catch (QueryException) {
            return 0;
        }
    }

    public function destroy(int $id): RedirectResponse
    {
        abort_unless($this->connection()->getSchemaBuilder()->hasTable('failed_jobs'), 404);

        $deleted = $this->connection()->table('failed_jobs')->where('id', $id)->delete();
        abort_unless($deleted > 0, 404);

        return redirect()->route('admin.failed-jobs')->with('success', 'Intrarea a fost eliminată din failed_jobs. Jobul nu a fost retrimis.');
    }

    private function connection(): Connection
    {
        // Feature tests should use their isolated database, never the live DO connection.
        return DB::connection(app()->environment('testing') ? config('database.default') : 'mysql_auth');
    }

    private function redactSecrets(string $exception): string
    {
        return preg_replace(
            '/(?i)((?:api[_-]?key|access[_-]?token|authorization|password|secret)\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;]+)/',
            '$1[redacted]',
            $exception,
        ) ?? $exception;
    }
}
