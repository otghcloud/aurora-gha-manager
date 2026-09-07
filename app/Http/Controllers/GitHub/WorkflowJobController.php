<?php

namespace App\Http\Controllers\GitHub;

use App\Http\Controllers\Controller;
use App\DataTables\Jobs\WorkflowJobsDataTable;
use App\Models\Infrastructure\Environment;
use App\Models\GitHub\WorkflowJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Presents recorded GitHub workflow jobs and their logs.
 */
class WorkflowJobController extends Controller
{
    /** Display recorded workflow jobs. */
    public function index(WorkflowJobsDataTable $dataTable): mixed
    {
        return $dataTable->render('pages.jobs.index', [
            'environments' => Environment::orderBy('name')->get(),
        ]);
    }

    /** Display one workflow job and its step history. */
    public function show(WorkflowJob $job): View
    {
        $job->load(['environment', 'runner.pool', 'runner.proxmoxTarget']);

        return view('pages.jobs.show', ['job' => $job]);
    }

    /** Return the stored workflow job log. */
    public function log(WorkflowJob $job): Response|StreamedResponse|BinaryFileResponse
    {
        $headers = [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="job-'.$job->github_job_id.'.log"',
        ];

        $realPath = $job->hasLog() ? realpath($job->log_path) : false;

        if ($realPath !== false && is_readable($realPath)) {
            return response()->file($realPath, $headers);
        }

        // The file may have been pruned; the stored copy is the durable source.
        $stored = $job->storedLog();

        if ($stored === null) {
            abort(404, 'Job log file is missing or not readable.');
        }

        return response($stored->body, 200, $headers);
    }

    /** Delete a workflow job and its retained log. */
    public function destroy(WorkflowJob $job): RedirectResponse
    {
        $job->delete();

        return redirect()->route('jobs.index')
            ->with('success', "Job {$job->job_name} has been deleted.");
    }
}
