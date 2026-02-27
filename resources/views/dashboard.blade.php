<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('HLS Streams Manager') }}
        </h2>
    </x-slot>

    <div class="mx-auto sm:px-6 lg:px-8 pb-6">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <iframe
                src="{{ route('streams.manager') }}"
                title="Streams Manager"
                style="width: 100%; min-height: calc(100vh - 180px); border: 0;"
            ></iframe>
        </div>
    </div>
</x-app-layout>
