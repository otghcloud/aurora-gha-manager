@extends('layouts.admin-base')

@section('meta-page-title', 'Templates')
@section('page-pretitle', 'Images')
@section('page-title', 'Templates')

@section('page-actions')
	<div class="col-auto ms-auto d-print-none">
		<div class="d-flex gap-2">
			<a class="btn btn-outline-secondary" href="{{ route('settings.templates.index') }}">
				<x-action-content icon="fa-solid fa-box-open" label="Manage template sources" />
			</a>
			<a class="btn btn-primary" href="{{ route('templates.create') }}">
				<x-action-content icon="fa-solid fa-plus" label="Add template" />
			</a>
		</div>
	</div>
@endsection

@section('page-content')
	<div class="container-xl">
		<p class="text-secondary">Proxmox VM templates that runners are cloned from. Import and review catalogs through template sources before creating runner templates.</p>
		<div class="row row-cards">
			@forelse ($templates as $template)
				@php($entry = $catalogEntries[$template->template_catalog_id] ?? null)
				@php($updateVersion = $availableUpdates[$template->id] ?? null)
				<div class="col-sm-6 col-xl-4">
					<div class="card h-100">
						<div class="card-header d-flex align-items-start justify-content-between gap-2">
							<div>
								<h3 class="card-title mb-1">{{ $template->name }}</h3>
								<div class="text-secondary small">{{ $template->environment->name }}</div>
							</div>
							<span class="badge bg-blue-lt">{{ $template->os->label() }}</span>
						</div>
						<div class="card-body">
							@if ($entry)
							<p class="text-secondary">{{ $entry->data()['description'] ?? 'No description provided.' }}</p>
								<span class="badge bg-{{ $entry->isBuildable() ? 'green' : 'secondary' }}-lt">
									{{ $entry->isBuildable() ? 'Builder available' : ($entry->disabledReason() ?? 'Builder unavailable') }}
								</span>
							@else
								<p class="text-secondary">The template catalog entry is not present in the active source.</p>
								<span class="badge bg-red-lt">Catalog unavailable</span>
							@endif
							@if ($updateVersion)
								<span class="badge bg-warning-lt ms-1">Update {{ $updateVersion }} available</span>
							@endif
							<div class="row mt-3 text-secondary small">
								<div class="col-6"><strong>{{ $template->targetMappings->count() }}</strong> mapped nodes</div>
								<div class="col-6"><strong>{{ $template->pools_count }}</strong> runner pools</div>
							</div>
						</div>
						<div class="card-footer d-flex justify-content-between">
							<a class="btn btn-sm btn-outline-primary" href="{{ route('templates.show', $template) }}">View details</a>
							<div class="d-flex gap-2">
								<a class="btn btn-sm btn-outline-secondary" href="{{ route('templates.edit', $template) }}">Edit</a>
								<a class="btn btn-sm btn-outline-danger" data-action="delete-modal" data-message="Delete {{ $template->name }}?" data-url="{{ route('templates.destroy', $template) }}" href="#">Delete</a>
							</div>
						</div>
					</div>
				</div>
			@empty
				<div class="col-12">
					<div class="card">
						<div class="card-body text-center py-5">
							<h3>No runner templates configured</h3>
							<p class="text-secondary">Import a validated catalog source, then add a runner template to start configuring node mappings.</p>
							<a class="btn btn-outline-secondary me-2" href="{{ route('settings.templates.index') }}">Manage template sources</a>
							<a class="btn btn-primary" href="{{ route('templates.create') }}">Add template</a>
						</div>
					</div>
				</div>
			@endforelse
		</div>
	</div>
@endsection
