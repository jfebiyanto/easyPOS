@extends('layouts.admin')

@section('title', 'Promo Priority')
@section('page_title', 'Promo Priority')

@section('content')
<div class="card" style="max-width: 760px;">
    <h2 class="card-title">Discount Source Priority Order</h2>
    <p class="card-subtitle">
        When an item at the register qualifies for several discounts, the highest source that produces a discount on that item is used.
        Its discount value is not compared with the sources below it; sources that do not apply are skipped.
        Within the same source, the largest discount wins.
    </p>

    <form method="POST" action="{{ route('admin.promotion-hierarchy.update') }}">
        @csrf @method('PUT')

        <ol id="hierarchy-list" style="list-style:none; margin:16px 0; padding:0; display:flex; flex-direction:column; gap:8px;">
            @foreach ($order as $key)
                <li data-key="{{ $key }}" style="display:flex; align-items:center; gap:12px; padding:10px 14px; border:1px solid var(--md-outline-variant); border-radius:8px; background:#fff;">
                    <span class="rank mono" style="min-width:24px; font-weight:600;"></span>
                    <input type="hidden" name="order[]" value="{{ $key }}">
                    <span style="flex:1;">{{ $sources[$key] }}</span>
                    <button type="button" class="btn btn-tonal btn-sm" data-move="-1" aria-label="Naikkan {{ $sources[$key] }}"><span class="material-symbols-rounded">arrow_upward</span></button>
                    <button type="button" class="btn btn-tonal btn-sm" data-move="1" aria-label="Turunkan {{ $sources[$key] }}"><span class="material-symbols-rounded">arrow_downward</span></button>
                </li>
            @endforeach
        </ol>
        @error('order') <div class="error">{{ $message }}</div> @enderror
        @error('order.*') <div class="error">{{ $message }}</div> @enderror

        <div class="form-actions">
            @unless ($readOnly)
            <button type="submit" class="btn btn-filled"><span class="material-symbols-rounded">save</span> Save</button>
            @endunless
            <a href="{{ route('admin.promotions.index') }}" class="btn btn-outline">Back</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const list = document.getElementById('hierarchy-list');
        const renumber = () => list.querySelectorAll('li').forEach((li, i) => { li.querySelector('.rank').textContent = (i + 1) + '.'; });

        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-move]');
            if (!button) return;

            const item = button.closest('li');
            const direction = Number(button.dataset.move);
            const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
            if (!sibling) return;

            if (direction < 0) list.insertBefore(item, sibling); else list.insertBefore(sibling, item);
            renumber();
            button.focus();
        });

        renumber();
    })();
</script>
@endpush
