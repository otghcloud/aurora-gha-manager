@extends('pages.settings.base')

@section('meta-page-title', 'Settings · Templates')
@section('page-title', 'Templates')

@section('page-sub-content')
	<div class="card-body">
		<div class="card mb-4">
			<div class="card-header card-header-light d-flex align-items-center justify-content-between">
				<h3 class="card-title mb-0">Automatic updates</h3>
				<button class="btn btn-sm btn-outline-secondary" form="check-template-updates-form" type="submit">
					<x-action-content icon="fa-solid fa-arrows-rotate" label="Check now" />
				</button>
			</div>
			<form action="{{ route('settings.templates.update') }}" method="POST">
				@csrf
				@method('PUT')

				<div class="card-body">
					<p class="text-secondary small">
						Automatically check GitHub for template index updates and notify when new versions are published.
					</p>
					<div class="mb-3">
						<label class="form-check form-switch">
							<input class="form-check-input" name="template_auto_check_enabled" type="checkbox" value="1" @checked(old('template_auto_check_enabled', $settings['template_auto_check_enabled'] ?? '0') == '1')>
							<span class="form-check-label">Automatically check for template updates</span>
						</label>
					</div>
					<div class="mb-3">
						<label class="form-label" for="template_check_interval_hours">Check interval (hours)</label>
						<input class="form-control" id="template_check_interval_hours" max="168" min="1" name="template_check_interval_hours" type="number" value="{{ old('template_check_interval_hours', $settings['template_check_interval_hours'] ?? 24) }}">
						<small class="form-hint">Queries GitHub template index every X hours for version updates.</small>
					</div>
					<div class="mb-3">
						<label class="form-check form-switch">
							<input class="form-check-input" name="template_auto_download_enabled" type="checkbox" value="1" @checked(old('template_auto_download_enabled', $settings['template_auto_download_enabled'] ?? '0') == '1')>
							<span class="form-check-label">Automatically download and activate updates once found</span>
						</label>
					</div>
					<div class="mb-0">
						<label class="form-check form-switch">
							<input class="form-check-input" name="template_auto_build_enabled" type="checkbox" value="1" @checked(old('template_auto_build_enabled', $settings['template_auto_build_enabled'] ?? '0') == '1')>
							<span class="form-check-label">Automatically rebuild templates after activating an update</span>
						</label>
					</div>
					<hr>
					<div class="mb-0">
						<label class="form-check form-switch">
							<input class="form-check-input" name="keep_failed_build_vm" type="checkbox" value="1" @checked(old('keep_failed_build_vm', $settings['keep_failed_build_vm'] ?? '0') == '1')>
							<span class="form-check-label">Keep failed build VM</span>
						</label>
						<small class="form-hint">Disabled by default. Retained VMs can be inspected and removed manually after a failed build.</small>
					</div>

					@if (! empty($settings[\App\Services\SettingsRepository::TEMPLATE_UPDATES_AVAILABLE]))
						@php($updateData = json_decode($settings[\App\Services\SettingsRepository::TEMPLATE_UPDATES_AVAILABLE], true))
						@if (is_array($updateData))
							<div class="alert alert-info mb-0">
								<div class="d-flex align-items-center gap-2">
									<i class="fa-solid fa-clock-rotate-left"></i>
									<div>
										<strong>Last checked:</strong> {{ ! empty($updateData['checked_at']) ? \Carbon\Carbon::parse($updateData['checked_at'])->diffForHumans() : 'Recently' }}
										&middot;
										@if (! empty($updateData['available']))
											<span class="text-warning fw-bold">{{ count($updateData['updates'] ?? []) }} update(s) available.</span>
										@else
											<span class="text-success">All templates up to date.</span>
										@endif
									</div>
								</div>
							</div>
						@endif
					@endif
				</div>
				<div class="card-footer text-end">
					<button class="btn btn-primary" type="submit">Save changes</button>
				</div>
			</form>
		</div>

		<div class="card mb-4">
			<div class="card-header card-header-light">
				<h3 class="card-title mb-0">Import a template source</h3>
			</div>
			<div class="card-body">
				<p class="text-secondary">
					Imports are checked against the template catalog schema and stored inactive. Review the installed bundle and explicitly activate it below.
					Remote sources are limited to public GitHub HTTPS repositories and codeload archives; host commands are never run.
				</p>
				<form action="{{ route('settings.templates.sources.import') }}" class="mb-4" enctype="multipart/form-data" method="POST">
					@csrf
					<input name="source_type" type="hidden" value="url">
					<label class="form-label" for="template-source-url">GitHub codeload archive URL</label>
					<div class="input-group">
						<input class="form-control" id="template-source-url" name="url" placeholder="https://codeload.github.com/owner/repository/tar.gz/refs/heads/main" required type="url">
						<button class="btn btn-outline-primary" type="submit">Validate and import URL</button>
					</div>
				</form>
				<form action="{{ route('settings.templates.sources.import') }}" class="mb-4" method="POST">
					@csrf
					<input name="source_type" type="hidden" value="repository">
					<label class="form-label" for="template-source-repository">GitHub repository</label>
					<div class="row g-2">
						<div class="col-md-7">
							<input class="form-control" id="template-source-repository" name="repository" placeholder="https://github.com/owner/repository" required type="url">
						</div>
						<div class="col-md-3">
							<input class="form-control" name="ref" placeholder="main" type="text" value="main">
						</div>
						<div class="col-md-2">
							<button class="btn btn-outline-primary w-100" type="submit">Import repository</button>
						</div>
					</div>
				</form>
				<form action="{{ route('settings.templates.sources.import') }}" enctype="multipart/form-data" method="POST">
					@csrf
					<input name="source_type" type="hidden" value="upload">
					<label class="form-label" for="template-source-archive">Uploaded .tar.gz archive (25 MiB maximum)</label>
					<div class="input-group">
						<input accept=".tar.gz,.tgz,application/gzip" class="form-control" id="template-source-archive" name="archive" required type="file">
						<button class="btn btn-outline-primary" type="submit">Validate and import archive</button>
					</div>
				</form>
				<small class="form-hint d-block mt-2">See <code>docs/templates/README.md</code> for the archive layout, schema, and offline validation command.</small>
			</div>
		</div>

		<div class="card">
			<div class="card-header card-header-light d-flex align-items-center justify-content-between">
				<h3 class="card-title mb-0">Installed template bundles</h3>
				<button class="btn btn-sm btn-outline-secondary" form="download-template-update-form" type="submit">
					<x-action-content icon="fa-solid fa-download" label="Download now" />
				</button>
			</div>
			<div class="table-responsive">
				<table class="table card-table table-vcenter">
					<thead><tr><th>Version</th><th>Downloaded</th><th>Status</th><th></th></tr></thead>
					<tbody>
						@forelse ($installedVersions as $bundle)
							<tr>
								<td>
									{{ $bundle['version'] }}
									@if ($bundle['bundled'])
										<span class="badge bg-blue-lt ms-1">Pre-packaged</span>
									@endif
								</td>
								<td>{{ \Carbon\Carbon::createFromTimestamp($bundle['downloaded_at'])->diffForHumans() }}</td>
								<td><span class="badge bg-{{ $bundle['active'] ? 'green' : 'secondary' }}-lt">{{ $bundle['active'] ? 'Active' : 'Installed' }}</span></td>
								<td class="text-end">
									@unless ($bundle['active'])
										<form action="{{ route('settings.templates.activate-version', $bundle['bundled'] ? \App\Services\Templates\TemplateDownloadService::BUNDLED : $bundle['version']) }}" method="POST">
											@csrf
											<button class="btn btn-sm"><x-action-content icon="fa-solid fa-rotate-left" label="Activate" /></button>
										</form>
									@endunless
								</td>
							</tr>
						@empty
							<tr><td colspan="4" class="text-secondary">No template bundles downloaded yet.</td></tr>
						@endforelse
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<form action="{{ route('settings.templates.check-updates') }}" id="check-template-updates-form" method="POST">
		@csrf
	</form>
	<form action="{{ route('settings.templates.download-update') }}" id="download-template-update-form" method="POST">
		@csrf
	</form>
@endsection

