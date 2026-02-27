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
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div class="rounded-lg border border-gray-200 p-4 bg-gray-50">
                            <p class="text-sm text-gray-500">{{ __('Total Streams') }}</p>
                            <p class="text-2xl font-bold">{{ $totalStreams ?? 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-green-200 p-4 bg-green-50">
                            <p class="text-sm text-green-700">{{ __('Running') }}</p>
                            <p class="text-2xl font-bold text-green-700">{{ $runningStreams ?? 0 }}</p>
                        </div>
                        <div class="rounded-lg border border-red-200 p-4 bg-red-50">
                            <p class="text-sm text-red-700">{{ __('Offline Streams') }}</p>
                            <p class="text-2xl font-bold text-red-700">{{ $offlineStreams ?? 0 }}</p>
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
</x-app-layout>
