{{--
    An outlined social media icon, drawn in the current text color:
    <x-social-icon name="instagram" />. Names: instagram, linkedin, facebook.
--}}
@props(['name'])
@switch($name)
    @case('instagram')
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5" ry="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17.5" cy="6.5" r="1.5"/></svg>
        @break
    @case('linkedin')
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="3" ry="3" fill="none" stroke="currentColor" stroke-width="2"/><line x1="8" y1="11" x2="8" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="8" y1="8" x2="8" y2="8.01" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/><path d="M12 17v-3.5c0-1.5 1-2.5 2.5-2.5s2.5 1 2.5 2.5V17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="11" x2="12" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        @break
    @case('facebook')
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5" ry="5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16.5 7H15c-1.7 0-2.5 1-2.5 2.5V22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="9.5" y1="12.5" x2="15.5" y2="12.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        @break
@endswitch
