<?php

namespace App\Http\Controllers\GitHub;

use App\DataTables\Accounts\GitHubAccountsDataTable;
use App\Http\Controllers\Controller;
use App\Http\Requests\GitHub\GitHubAccountRequest;
use App\Models\GitHub\GitHubAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Manages GitHub account configuration.
 */
class GitHubAccountController extends Controller
{
    /** Display configured GitHub accounts. */
    public function index(GitHubAccountsDataTable $dataTable): mixed
    {
        return $dataTable->render('pages.github-accounts.index');
    }

    /** Display the GitHub account creation form. */
    public function create(): View
    {
        return view('pages.github-accounts.create', ['account' => new GitHubAccount(['github_api_url' => 'https://api.github.com', 'github_runner_group_id' => 1, 'github_work_folder' => '_work'])]);
    }

    /** Persist a new GitHub account configuration. */
    public function store(GitHubAccountRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['webhook_id'] ??= Str::uuid()->toString();

        $account = GitHubAccount::create($data);

        return redirect()->route('github-accounts.index')->with('success', "GitHub account {$account->login} created.");
    }

    /** Display the GitHub account edit form. */
    public function edit(GitHubAccount $githubAccount): View
    {
        return view('pages.github-accounts.edit', ['account' => $githubAccount]);
    }

    /** Update an existing GitHub account configuration. */
    public function update(GitHubAccountRequest $request, GitHubAccount $githubAccount): RedirectResponse
    {
        $data = $request->validated();
        foreach (['github_token', 'github_webhook_secret'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }
        $githubAccount->update($data);

        return redirect()->route('github-accounts.index')->with('success', 'GitHub account updated.');
    }

    /** Delete a GitHub account configuration. */
    public function destroy(GitHubAccount $githubAccount): RedirectResponse
    {
        $githubAccount->delete();

        return redirect()->route('github-accounts.index')->with('success', 'GitHub account deleted.');
    }
}
