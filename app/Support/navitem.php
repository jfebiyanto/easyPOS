<?php

use App\Models\Store;

if (! function_exists('navitem')) {
    /**
     * MD3 navigation item: renders an active-aware link.
     */
    function navitem(string $routeName, string $icon, string $label): string
    {
        $url = route($routeName);
        $active = request()->routeIs($routeName) || request()->routeIs(str_replace('.index', '.*', $routeName));

        return '<a href="'.$url.'" class="'.($active ? 'active' : '').'">
            <span class="material-symbols-rounded">'.$icon.'</span>
            <span>'.$label.'</span>
        </a>';
    }
}

if (! function_exists('active_store_id')) {
    /**
     * ID store yang menjadi konteks data master saat ini.
     * Fallback: store milik user, lalu store pertama.
     */
    function active_store_id(): ?string
    {
        if ($id = session('active_store_id')) {
            return $id;
        }

        $id = auth()->user()?->store_id ?? Store::query()->orderBy('code')->value('id');

        if ($id) {
            session()->put('active_store_id', $id);
        }

        return $id;
    }
}

if (! function_exists('sort_link')) {
    /**
     * Header kolom tabel yang bisa diklik untuk sorting asc/desc.
     * Panah tampil hanya pada kolom yang sedang aktif disorting.
     */
    function sort_link(string $label, string $column): string
    {
        $currentSort = request('sort');
        $currentDir = request('dir', 'asc');
        $isActive = $currentSort === $column;
        $nextDir = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';

        $url = request()->fullUrlWithQuery(['sort' => $column, 'dir' => $nextDir]);
        $html = e($label);

        if ($isActive) {
            $icon = $currentDir === 'asc' ? 'arrow_upward' : 'arrow_downward';
            $html .= '<span class="material-symbols-rounded sort-arrow">'.$icon.'</span>';
        }

        $class = $isActive ? ' class="sort-active"' : '';

        return '<a href="'.$url.'"'.$class.' data-sort-column="'.$column.'">'.$html.'</a>';
    }
}

if (! function_exists('apply_sort')) {
    /**
     * Terapkan sorting dari query string dengan whitelist kolom.
     * Kolom berakhiran _count (hasil withCount) juga didukung.
     */
    function apply_sort($query, ?string $sort, ?string $dir, array $whitelist, string $defaultColumn, string $defaultDir = 'desc')
    {
        $dir = in_array(strtolower((string) $dir), ['asc', 'desc'], true) ? strtolower($dir) : $defaultDir;

        if ($sort && in_array($sort, $whitelist, true)) {
            return $query->orderBy($sort, $dir);
        }

        return $query->orderBy($defaultColumn, $defaultDir);
    }
}