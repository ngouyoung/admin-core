{{-- Avatar cell for a DataTable column (rendered server-side by WebController::avatar()).
     Delegates to the avatar component so a list cell looks like the avatar everywhere
     else. --}}
<x-admin-core::avatar :src="$src" :name="$name" :size="$size ?? 32" />
