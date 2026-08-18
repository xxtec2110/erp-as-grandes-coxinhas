@php
    $value = $data['value'] ?? null;
    $display = match($data['format'] ?? null) {
        'money' => $value === null ? null : 'R$ '.\App\Support\DecimalFormatter::format((string) $value, 2),
        'percent' => $value === null ? null : \App\Support\DecimalFormatter::format((string) $value, 2).'%',
        default => $value === null ? null : \App\Support\DecimalFormatter::format((string) $value, 0),
    };
@endphp
<section class="h-full min-h-44 rounded-2xl border border-white/10 bg-gradient-to-br from-white/[0.07] to-white/[0.02] p-5 shadow-xl">
    <div class="flex items-start justify-between gap-3"><p class="text-xs font-black uppercase tracking-[0.16em] text-stone-400">{{ $widget['name'] }}</p><span class="h-2.5 w-2.5 rounded-full {{ $display === null ? 'bg-stone-600' : 'bg-amber-400 shadow-[0_0_16px_rgba(251,191,36,.7)]' }}"></span></div>
    @if($display === null)<p class="mt-7 text-xl font-black text-stone-300">Sem dados no período</p>@else<p class="mt-6 text-3xl font-black tracking-tight text-white">{{ $display }}</p>@endif
    <p class="mt-3 text-xs leading-5 text-stone-400">{{ $data['caption'] ?? $widget['description'] }}</p>
</section>
