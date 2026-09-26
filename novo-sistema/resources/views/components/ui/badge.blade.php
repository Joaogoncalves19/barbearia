{{-- Rotulo de status. variant: neutral | success | warning | danger | info | accent | sample --}}
@props(['variant' => 'neutral', 'plain' => false])
<span {{ $attributes->class(['badge', 'badge--'.$variant => $variant !== 'neutral', 'badge--plain' => $plain]) }}>{{ $slot }}</span>
