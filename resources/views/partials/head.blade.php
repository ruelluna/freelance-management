<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/zt-logo.png" type="image/png" media="(prefers-color-scheme: light)">
<link rel="icon" href="/zt-logo-white.png" type="image/png" media="(prefers-color-scheme: dark)">
<link rel="apple-touch-icon" href="/zt-logo.png">

<script>
    (() => {
        const applyDarkTheme = () => {
            const stored = localStorage.getItem('dark-theme');
            const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const dark = stored === 'true'
                || stored === 'dark'
                || (stored === 'system' && systemDark)
                || (stored !== 'false' && stored !== 'light' && stored !== 'system');

            document.documentElement.classList.toggle('dark', dark);
        };

        applyDarkTheme();

        document.addEventListener('alpine:navigating', (event) => {
            event.detail?.onSwap?.(() => applyDarkTheme());
        });

        document.addEventListener('livewire:navigated', () => applyDarkTheme());
    })();
</script>

@fonts

<tallstackui:script />
@livewireStyles
@vite(['resources/css/app.css', 'resources/js/app.js'])
