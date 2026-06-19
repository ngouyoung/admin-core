{{-- CSV export dropdown with a column picker. Every column starts checked
     (export everything); unticking narrows the ?columns[] the export streams.
     Usage:
       <x-admin-core::export-menu :route="route('admin.products.export')" :fields="[
           'id' => 'ID', 'name' => 'Name', 'created_at' => 'Created at',
       ]" />
     `fields` is a value => label map (the column key sent as columns[], and its
     label). The controller still whitelists whatever is actually requested. --}}
@props(['route', 'fields' => []])
<div class="dropdown d-inline-block">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
        <i class="bi bi-filetype-csv"></i> Export
    </button>
    {{-- A GET form: the checked columns become ?columns[] and the export streams just those. --}}
    <form action="{{ $route }}" method="GET" class="dropdown-menu p-3" style="min-width: 240px;">
        <p class="small text-muted mb-2">Columns to export (leave all checked for everything):</p>
        <div style="max-height: 260px; overflow-y: auto;">
            @foreach ($fields as $col => $label)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="columns[]" value="{{ $col }}" id="exp-{{ $col }}" checked>
                    <label class="form-check-label" for="exp-{{ $col }}">{{ $label }}</label>
                </div>
            @endforeach
        </div>
        <button type="submit" class="btn btn-sm btn-primary w-100 mt-2"><i class="bi bi-download"></i> Download CSV</button>
    </form>
</div>
