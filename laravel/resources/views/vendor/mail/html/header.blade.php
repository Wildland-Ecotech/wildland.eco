{{-- Overrides Laravel's mail header with the site's wordmark, styled by the wildland theme --}}
@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
Wildland <span class="eco">Ecotech</span>
</a>
</td>
</tr>
