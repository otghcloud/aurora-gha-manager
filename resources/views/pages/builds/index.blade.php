@extends('layouts.admin-base-datatable')

@section('meta-page-title', 'Builds')
@section('page-pretitle', 'Images')
@section('page-title', 'Builds')
@section('page-table-description', 'Image builds across all configured builders.')

@section('page-table-filters')
	<select class="form-select w-auto" id="filter-template" aria-label="Filter by template">
		<option value="">All templates</option>
		@foreach ($templates as $template)
			<option value="{{ $template->id }}">{{ $template->name }}</option>
		@endforeach
	</select>
	<select class="form-select w-auto" id="filter-node" aria-label="Filter by Proxmox node">
		<option value="">All nodes</option>
		@foreach ($nodes as $node)
			<option value="{{ $node->id }}">{{ $node->name }}</option>
		@endforeach
	</select>
	<select class="form-select w-auto" id="filter-status" aria-label="Filter by build status">
		<option value="">All statuses</option>
		@foreach (\App\Enums\BuildStatus::cases() as $status)
			<option value="{{ $status->value }}">{{ $status->label() }}</option>
		@endforeach
	</select>
@endsection

@section('page-table-summary')
	<div class="row row-cards">
		<div class="col-sm-4">
			<div class="card card-sm">
				<div class="card-body">
					<div class="text-secondary small">Built</div>
					<div class="h2 mb-0">{{ $builtCount }}</div>
				</div>
			</div>
		</div>
		<div class="col-sm-4">
			<div class="card card-sm">
				<div class="card-body">
					<div class="text-secondary small">Failed</div>
					<div class="h2 mb-0">{{ $failedCount }}</div>
				</div>
			</div>
		</div>
		<div class="col-sm-4">
			<div class="card card-sm">
				<div class="card-body">
					<div class="text-secondary small">In progress</div>
					<div class="h2 mb-0">{{ $inProgressCount }}</div>
				</div>
			</div>
		</div>
	</div>
@endsection
