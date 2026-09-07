<div wire:poll.15s class="card-group mb-3">
	@foreach (\App\Enums\RunnerState::cases() as $state)
		@continue($state === \App\Enums\RunnerState::Reaping || $state === \App\Enums\RunnerState::Destroyed)
		<div class="card">
			<div class="card-body">
				<div class="subheader">{{ $state->label() }}</div>
				<div class="h1 mb-0">{{ $stateCounts[$state->name] ?? 0 }}</div>
			</div>
		</div>
	@endforeach
	<a class="card card-link" href="{{ route('builds.index') }}">
		<div class="card-body">
			<div class="subheader">Active builds</div>
			<div class="h1 mb-0">{{ $activeBuildsCount }}</div>
		</div>
	</a>
</div>
