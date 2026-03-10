<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="flex items-end justify-between mb-4">
                        <p class="text-sm text-gray-500">
                            {{ __('Live infrastructure summary') }}
                        </p>
                        <p class="text-xs text-gray-400" id="stats-updated-at">
                            {{ __('Updated:') }} {{ $updatedAt ?? now()->toDateTimeString() }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6" id="stats-cards">
                        <div class="rounded-xl border border-slate-200 p-5 bg-gradient-to-br from-slate-50 to-white">
                            <p class="text-xs uppercase tracking-wider text-slate-500">{{ __('Total Streams') }}</p>
                            <p class="text-3xl font-extrabold text-slate-800 mt-2" id="stat-total">{{ $totalStreams ?? 0 }}</p>
                        </div>
                        <div class="rounded-xl border border-emerald-200 p-5 bg-gradient-to-br from-emerald-50 to-white">
                            <p class="text-xs uppercase tracking-wider text-emerald-700">{{ __('Running') }}</p>
                            <p class="text-3xl font-extrabold text-emerald-700 mt-2" id="stat-running">{{ $runningStreams ?? 0 }}</p>
                        </div>
                        <div class="rounded-xl border border-rose-200 p-5 bg-gradient-to-br from-rose-50 to-white">
                            <p class="text-xs uppercase tracking-wider text-rose-700">{{ __('Offline Streams') }}</p>
                            <p class="text-3xl font-extrabold text-rose-700 mt-2" id="stat-offline">{{ $offlineStreams ?? 0 }}</p>
                        </div>
                    </div>

                    <a href="{{ route('streams') }}"
                       class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        {{ __('Manage Streams') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const totalEl = document.getElementById('stat-total');
            const runningEl = document.getElementById('stat-running');
            const offlineEl = document.getElementById('stat-offline');
            const updatedAtEl = document.getElementById('stats-updated-at');

            async function refreshStats() {
                try {
                    const res = await fetch(@json(route('dashboard.stats')), { headers: { 'Accept': 'application/json' } });
                    if (!res.ok) return;

                    const data = await res.json();
                    if (typeof data.totalStreams === 'number') totalEl.textContent = data.totalStreams;
                    if (typeof data.runningStreams === 'number') runningEl.textContent = data.runningStreams;
                    if (typeof data.offlineStreams === 'number') offlineEl.textContent = data.offlineStreams;
                    if (data.updatedAt) updatedAtEl.textContent = 'Updated: ' + data.updatedAt;
                } catch (e) {
                    // Ignore temporary polling errors.
                }
            }

            setInterval(refreshStats, 3000);
        })();
    </script>
</x-app-layout>
