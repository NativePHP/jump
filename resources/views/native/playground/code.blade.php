{{--
    Syntax-highlighted PHP block. Pass `$code` (a snippet). One <text> per line,
    each token an inline coloured run (see App\Support\CodeHighlighter). Leading
    indentation is kept as its own run so it survives the renderer's whitespace rules.
--}}
<column class="w-full p-3 gap-1 rounded-lg bg-[#F6F8FA] dark:bg-[#0B0B0D] border border-theme-outline">
    @foreach (App\Support\CodeHighlighter::lines($code) as $line)
        <text class="text-xs font-mono">@foreach ($line as [$token, $kind])<text class="{{ App\Support\CodeHighlighter::classes($kind) }}">{{ str_replace(' ', "\u{00A0}", $token) }}</text>@endforeach</text>
    @endforeach
</column>
