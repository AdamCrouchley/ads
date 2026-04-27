<x-filament-panels::page>
    <div class="max-w-3xl space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-8 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                {{ $this->getHeading() }}
            </h2>

            <p class="mt-3 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                {{ $description }}
            </p>

            @if(!empty($bullets))
                <ul class="mt-6 space-y-2">
                    @foreach($bullets as $bullet)
                        <li class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                            <svg class="mt-0.5 h-4 w-4 flex-none text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                            <span>{{ $bullet }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-8 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-900/20 dark:text-amber-200">
                <span class="font-medium">Status:</span> {{ $cta }}
            </div>
        </div>
    </div>
</x-filament-panels::page>
