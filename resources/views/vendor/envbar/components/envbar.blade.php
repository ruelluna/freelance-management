<div @class([
        'eb:z-50 eb:w-full',
        'eb:top-0' => $configuration['bottom'] === false,
        'eb:bottom-0' => $configuration['bottom'] === true,
        'eb:p-0.5' => $configuration['size'] === 'xs',
        'eb:p-1' => $configuration['size'] === 'sm',
        'eb:p-3' => $configuration['size'] === 'md',
        'eb:p-4' => $configuration['size'] === 'lg',
        'eb:p-5' => $configuration['size'] === 'xl',
        'eb:fixed' => $configuration['fixed'],
        $colors['background']
    ]) id="envbar">
    <div class="eb:flex eb:flex-wrap eb:items-center eb:space-x-1 eb:gap-1">
        <div class="eb:inline-flex eb:items-center eb:gap-1">
            <x-envbar::icons.laravel @class($colors['icons']) />
            <p>@lang('envbar::messages.environment')</p>
            <x-envbar::badge :size="$configuration['size']">
                {{ $environment['environment'] }}
            </x-envbar::badge>
        </div>
        @if ($environment['branch'] !== null)
        <div class="eb:flex eb:flex-row eb:items-center eb:gap-1">
            <x-envbar::icons.fork @class($colors['icons']) />
            <div class="eb:inline-flex eb:items-center">
                <p>@lang('envbar::messages.branch')</p>
                <x-envbar::badge :size="$configuration['size']">{{ $environment['branch'] }}</x-envbar::badge>
            </div>
        </div>
        @endif
        @if ($environment['release'] !== null)
        <div class="eb:inline-flex eb:items-center eb:gap-1">
            <x-envbar::icons.tag @class($colors['icons']) />
            <p>@lang('envbar::messages.release', ['source' => $environment['provider']])</p>
            <x-envbar::badge :size="$configuration['size']">{{ $environment['release'] }}</x-envbar::badge>
        </div>
        @endif
        @if ($configuration['warning_message'])
            <x-envbar::icons.exclamation-circle />
            <div>
                {!! $configuration['warning_message'] !!}
            </div>
        @endif
        <div class="eb:flex eb:items-center eb:absolute eb:right-2">
            @if ($configuration['links'] !== null)
                <div class="eb:items-center eb:gap-1">
                    <select id="envbar-dropdown" class="eb:w-full eb:rounded-md eb:border-0 eb:py-0.5 eb:pl-3 eb:pr-10 eb:text-gray-900 eb:ring-1 eb:ring-inset eb:ring-gray-300 eb:focus:outline-none eb:focus:ring-1 eb:focus:ring-gray-300">
                        <option value="">@lang('envbar::messages.select')</option>
                        @foreach ($configuration['links'] as $link)
                            <option value="{{ $link['url'] }}">{{ $link['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if ($configuration['tailwind_breaking_points'])
            <div class="eb:items-center eb:gap-1">
                <x-envbar::badge :size="$configuration['size']"><span id="envbar-resolution"></span></x-envbar::badge>
            </div>
            @endif
            @if ($configuration['closable']['enabled'])
                <button type="button" id="envbar-close" dusk="envbar_close_button" aria-label="Dismiss environment bar" style="cursor: pointer">
                    <x-envbar::icons.x class="eb:h-4 eb:w-4" />
                </button>
            @endif
        </div>
    </div>
</div>

<script{!! $nonce !!}>
    (function () {
        const configuration = @js($configuration);
        const forceShow = @js($show);
        const bar = document.getElementById('envbar');

        const syncOffset = () => {
            const hidden = !bar || bar.style.display === 'none';

            document.documentElement.style.setProperty('--envbar-offset', hidden ? '0px' : `${bar.offsetHeight}px`);
        };

        const breakpoint = () => {
            const span = document.getElementById('envbar-resolution');

            if (!span) {
                return;
            }

            const width = window.innerWidth;

            if (width < 640) {
                span.textContent = 'XS';
            } else if (width < 768) {
                span.textContent = 'SM';
            } else if (width < 1024) {
                span.textContent = 'MD';
            } else if (width < 1280) {
                span.textContent = 'LG';
            } else if (width < 1536) {
                span.textContent = 'XL';
            } else {
                span.textContent = '2XL';
            }
        };

        const boot = () => {
            let closedAt = localStorage.getItem('envbar::closed');

            if (forceShow === true || configuration.closable?.enabled !== true) {
                localStorage.removeItem('envbar::closed');
                closedAt = null;
                bar.style.display = '';
            }

            if (closedAt && Date.now() > Number.parseInt(closedAt, 10)) {
                localStorage.removeItem('envbar::closed');
                closedAt = null;
            }

            if (closedAt && Date.now() < Number.parseInt(closedAt, 10)) {
                bar.style.display = 'none';
            }

            if (configuration.tailwind_breaking_points) {
                breakpoint();
            }

            syncOffset();
        };

        boot();
        window.addEventListener('resize', () => {
            if (configuration.tailwind_breaking_points) {
                breakpoint();
            }

            syncOffset();
        });
        document.addEventListener('livewire:navigated', boot);

        const close = document.getElementById('envbar-close');

        if (close) {
            close.addEventListener('click', () => {
                const timeout = configuration.closable?.timeout ?? null;

                bar.style.display = 'none';
                syncOffset();

                if (!timeout) {
                    localStorage.removeItem('envbar::closed');

                    return;
                }

                localStorage.setItem('envbar::closed', String(Date.now() + Number.parseInt(timeout, 10) * 60000));
            });
        }

        const dropdown = document.getElementById('envbar-dropdown');

        if (dropdown) {
            dropdown.addEventListener('change', () => {
                if (dropdown.value === '') {
                    return;
                }

                window.open(dropdown.value, '_blank', 'noopener');
                dropdown.value = '';
            });
        }
    })();
</script>
