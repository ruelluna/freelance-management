@if (filled($content))
    <div {{ $attributes->class([
        'issue-markdown text-sm leading-relaxed text-zinc-800 dark:text-zinc-200',
        '[&_img]:my-4 [&_img]:max-w-full [&_img]:h-auto [&_img]:rounded-lg [&_img]:border [&_img]:border-zinc-200 dark:[&_img]:border-zinc-700',
        '[&_a]:text-blue-600 [&_a]:underline dark:[&_a]:text-blue-400',
        '[&_p]:mb-3 [&_ul]:mb-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:mb-3 [&_ol]:list-decimal [&_ol]:pl-5',
        '[&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-zinc-100 [&_pre]:p-3 dark:[&_pre]:bg-zinc-800',
        '[&_code]:rounded [&_code]:bg-zinc-100 [&_code]:px-1 dark:[&_code]:bg-zinc-800',
    ]) }}>
        {!! $html() !!}
    </div>
@endif
