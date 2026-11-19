@extends('pages.settings.base')

@section('meta-page-title', 'Settings · Overview')
@section('page-title', 'Overview')

@section('page-sub-content')
	<div class="card-body">
		<h3 class="subheader">Configuration</h3>
		<div class="row row-cards mb-4">
			<div class="col-md-6 col-xl-4">
				<div class="card card-sm">
					<div class="card-body">
						<div class="text-secondary small">Nodes</div>
						<div class="h3 mb-0">{{ $nodeCount }}</div>
					</div>
				</div>
			</div>
			<div class="col-md-6 col-xl-4">
				<div class="card card-sm">
					<div class="card-body">
						<div class="text-secondary small">Pools</div>
						<div class="h3 mb-0">{{ $poolCount }}</div>
					</div>
				</div>
			</div>
			<div class="col-md-6 col-xl-4">
				<div class="card card-sm">
					<div class="card-body">
						<div class="text-secondary small">Runner Templates</div>
						<div class="h3 mb-0">{{ $templateCount }}</div>
					</div>
				</div>
			</div>
		</div>

		<h3 class="subheader">Lifetime usage</h3>
		<div class="row row-cards mb-4">
			<div class="col-md-4">
				<div class="card card-sm">
					<div class="card-body">
						<div class="text-secondary small">Runner records</div>
						<div class="h3 mb-0">{{ $runnerCount }}</div>
					</div>
				</div>
			</div>
			<div class="col-md-4">
				<div class="card card-sm">
					<div class="card-body">
						<div class="text-secondary small">Workflow jobs served</div>
						<div class="h3 mb-0">{{ $jobCount }}</div>
					</div>
				</div>
			</div>
			<div class="col-md-4">
				<div class="card card-sm">
					<div class="card-body">
						<div class="text-secondary small">Image build records</div>
						<div class="h3 mb-0">{{ $buildCount }}</div>
					</div>
				</div>
			</div>
		</div>
		<p class="text-secondary small">Usage totals count records currently retained in this installation; deleted or purged history is not included.</p>

		<div class="card">
			<div class="card-header card-header-light">
				<h3 class="card-title mb-0">Installation</h3>
			</div>
			<div class="card-body">
				<dl class="row mb-0">
					<dt class="col-5">Installed</dt>
					<dd class="col-7">{{ !empty($settings['installed_at']) ? \Illuminate\Support\Carbon::parse($settings['installed_at'])->forDisplay()->format('d/m/Y H:i:s') : '—' }}</dd>
					<dt class="col-5">Database</dt>
					<dd class="col-7">{{ ucfirst(config('database.default')) }}</dd>
					<dt class="col-5">Queue</dt>
					<dd class="col-7">{{ ucfirst(config('queue.default')) }}</dd>
					<dt class="col-5">Application Version</dt>
					<dd class="col-7">{{ app_version() }}</dd>
					<dt class="col-5">Bundled Templates Version</dt>
					<dd class="col-7">{{ $templatesVersion ?: '—' }}</dd>
				</dl>
			</div>
		</div>

		<div class="card mt-4">
			<div class="card-header card-header-light">
				<h3 class="card-title mb-0">Installed Plugins</h3>
			</div>
			<div class="card-body">
				@if ($plugins === [])
					<p class="text-secondary mb-0">No builder plugins are installed.</p>
				@else
					<dl class="row mb-0">
						@foreach ($plugins as $name => $version)
							<dt class="col-7">{{ $name }}</dt>
							<dd class="col-5">{{ $version }}</dd>
						@endforeach
					</dl>
				@endif
			</div>
		</div>

		<div class="card mt-4">
			<div class="card-header card-header-light">
				<h3 class="card-title mb-0">Template Sources</h3>
			</div>
			<div class="card-body">
				<dl class="row mb-0">
					@foreach ($templateSources as $name => $source)
						<dt class="col-5">{{ $name }}</dt>
						<dd class="col-7 text-break">{{ $source !== '' ? $source : '—' }}</dd>
					@endforeach
				</dl>
			</div>
		</div>
	</div>
@endsection
