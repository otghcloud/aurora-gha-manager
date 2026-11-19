@extends('layouts.admin-base')

@section('meta-page-title', 'Import restored template')
@section('page-pretitle', 'Templates')
@section('page-title', 'Import restored template')

@section('page-content')
	<div class="container-xl">
		<div class="alert alert-info">
			Restore your backup in Proxmox first, then register the restored template here. This does not restore a backup, copy disks, or run a build.
			The imported VM must already contain the selected template's runner software and accept its configured runner credential.
		</div>
		<form action="{{ route('templates.import') }}" class="card mb-3" method="GET">
			@if ($selectedTemplateId)
				<input name="runner_template_id" type="hidden" value="{{ $selectedTemplateId }}">
			@endif
			<div class="card-header"><h3 class="card-title">Destination node</h3></div>
			<div class="card-body">
				<label class="form-label required" for="proxmox_target_id">Node containing the restored template</label>
				<div class="d-flex gap-2">
					<select class="form-select" id="proxmox_target_id" name="proxmox_target_id" required>
						<option value="">Select a node</option>
						@foreach ($targets as $node)
							<option value="{{ $node->id }}" @selected($target?->id === $node->id)>{{ $node->name }} ({{ $node->proxmox_node }})</option>
						@endforeach
					</select>
					<button class="btn text-nowrap" type="submit">Load storage options</button>
				</div>
			</div>
		</form>

		@if ($storageError)
			<div class="alert alert-danger" role="alert">{{ $storageError }}</div>
		@endif

		@if ($target !== null)
			<form action="{{ route('templates.import.store') }}" class="card" method="POST">
				@csrf
				<input name="proxmox_target_id" type="hidden" value="{{ $target->id }}">
				<div class="card-header"><h3 class="card-title">Restored template on {{ $target->name }}</h3></div>
				<div class="card-body">
					<div class="row g-3">
						<div class="col-md-6">
							<label class="form-label required" for="runner_template_id">Runner template</label>
							<select class="form-select @error('runner_template_id') is-invalid @enderror" id="runner_template_id" name="runner_template_id" required>
								<option value="">Select a configured template</option>
								@foreach ($templates as $template)
									<option value="{{ $template->id }}" @selected($selectedTemplateId == $template->id)>{{ $template->name }} ({{ $template->environment?->name ?? 'No environment' }})</option>
								@endforeach
							</select>
							<small class="form-hint">Use the same definition as the original build. <a href="{{ route('templates.create') }}">Add a template definition</a> first if necessary.</small>
							@error('runner_template_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
						</div>
						<div class="col-md-6">
							<label class="form-label required" for="source_storage">Source storage</label>
							<select class="form-select @error('source_storage') is-invalid @enderror" id="source_storage" name="source_storage" required>
								<option value="">Select template disk storage</option>
								@foreach ($storages as $storage)
									<option value="{{ $storage['storage'] }}" @selected(old('source_storage') === $storage['storage'])>{{ $storage['storage'] }} ({{ $storage['type'] ?? 'images' }})</option>
								@endforeach
							</select>
							<small class="form-hint">Storage holding an attached disk of the restored template on this node, not the storage holding its backup archive.</small>
							@error('source_storage')<div class="invalid-feedback">{{ $message }}</div>@enderror
							@if ($storages === [] && ! $storageError)<div class="text-danger small">No active image storage was found on this node.</div>@endif
						</div>
						<div class="col-md-6">
							<label class="form-label required" for="template_vmid">Restored template VMID</label>
							<input class="form-control @error('template_vmid') is-invalid @enderror" id="template_vmid" max="{{ $target->template_vmid_range_end }}" min="{{ $target->template_vmid_range_start }}" name="template_vmid" required type="number" value="{{ old('template_vmid') }}">
							<small class="form-hint">Use the VMID assigned during restore, within this node's template range ({{ $target->template_vmid_range_start }}-{{ $target->template_vmid_range_end }}). The template must be stopped and unlocked.</small>
							@error('template_vmid')<div class="invalid-feedback">{{ $message }}</div>@enderror
						</div>
						<div class="col-md-6">
							<label class="form-label" for="version">Image version (optional)</label>
							<input class="form-control @error('version') is-invalid @enderror" id="version" maxlength="255" name="version" type="text" value="{{ old('version') }}">
							<small class="form-hint">The version originally built, if known. Leaving this blank records an unknown version, not the current catalog version.</small>
							@error('version')<div class="invalid-feedback">{{ $message }}</div>@enderror
						</div>
						<div class="col-12 text-secondary">
							Import makes this node available to eligible runner pools using the template. Existing physical mappings are not overwritten.
							Runner VM Storage still controls where newly cloned runners are placed; import does not move the template.
						</div>
					</div>
				</div>
				<div class="card-footer text-end">
					<a class="btn btn-link" href="{{ route('templates.index') }}">Cancel</a>
					<button class="btn btn-primary" @disabled($storages === [] || $templates->isEmpty()) type="submit">Import template</button>
				</div>
			</form>
		@endif
	</div>
@endsection
