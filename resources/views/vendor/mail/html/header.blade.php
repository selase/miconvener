@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- An absolute URL: a mail client has no page to resolve a relative one
     against. The alt text carries the brand for anyone whose client blocks
     remote images, which is why it reads as the name rather than "logo". --}}
<img src="{{ asset('assets/img/brand/miconvener@2x.png') }}"
     class="logo"
     alt="{{ config('app.name', 'MiConvener') }}"
     style="height: 34px; width: auto; max-width: 260px; border: 0;">
</a>
</td>
</tr>
