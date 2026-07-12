{{-- Row template for the virtual-list live example — the docs point `item`
     at exactly this view name (native.rows.contact). Rows are synthesized
     from the window index the component passes in; no backing collection. --}}
<native:list-item
    headline="Contact {{ $index + 1 }}"
    supporting="contact{{ $index + 1 }}@example.com" />
