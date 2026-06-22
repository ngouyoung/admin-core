{{-- A reusable Bootstrap modal shell. Usage:
       <button data-bs-toggle="modal" data-bs-target="#editX">Edit</button>
       <x-admin-core::modal id="editX" title="Edit item">
           ...body...
           <x-slot:footer>
               <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
               <button class="btn btn-primary">Save</button>
           </x-slot:footer>
       </x-admin-core::modal>
     `size` is '' | sm | lg | xl. Pass `centered` to vertically center the dialog. Omit
     `title` (and the header slot) for a chrome-less modal. Trigger it from any element
     with data-bs-target="#{id}". --}}
@props(['id', 'title' => null, 'size' => null, 'centered' => false])
<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog{{ $size ? ' modal-' . $size : '' }}{{ $centered ? ' modal-dialog-centered' : '' }}">
        <div {{ $attributes->merge(['class' => 'modal-content']) }}>
            @if ($title || isset($header))
                <div class="modal-header">
                    <h5 class="modal-title">{{ $header ?? $title }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            @endif
            <div class="modal-body">{{ $slot }}</div>
            @isset($footer)<div class="modal-footer">{{ $footer }}</div>@endisset
        </div>
    </div>
</div>
