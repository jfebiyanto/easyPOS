<?php

use App\Models\CompanyDetail;
use App\Models\Store;
use App\Models\StoreClosure;
use Illuminate\Support\Carbon;

if (! function_exists('navitem_active')) {
    /**
     * Apakah route menu ini (atau halaman turunannya, mis. create/edit) sedang dibuka.
     */
    function navitem_active(string $routeName): bool
    {
        return request()->routeIs($routeName) || request()->routeIs(str_replace('.index', '.*', $routeName));
    }
}

if (! function_exists('navitem')) {
    /**
     * MD3 navigation item: renders an active-aware link.
     */
    function navitem(string $routeName, string $icon, string $label): string
    {
        $url = route($routeName);
        $active = navitem_active($routeName);

        return '<a href="'.$url.'" class="'.($active ? 'active' : '').'">
            <span class="material-symbols-rounded">'.$icon.'</span>
            <span>'.$label.'</span>
        </a>';
    }
}

if (! function_exists('current_company_id')) {
    /**
     * ID perusahaan aktif (single-row). Tidak membuat baris baru saat dibaca.
     */
    function current_company_id(): ?string
    {
        return CompanyDetail::query()->orderBy('created_at')->value('id');
    }
}

if (! function_exists('company_timezone')) {
    function company_timezone(): string
    {
        return CompanyDetail::query()->value('timezone') ?: 'Asia/Jakarta';
    }
}

if (! function_exists('company_today')) {
    function company_today(): string
    {
        return now(company_timezone())->toDateString();
    }
}

if (! function_exists('company_business_date')) {
    function company_business_date($at = null): string
    {
        $at = $at ? Carbon::parse($at) : now();

        return $at->setTimezone(company_timezone())->toDateString();
    }
}

if (! function_exists('store_business_status')) {
    /**
     * Status tanggal bisnis store untuk ditampilkan di POS & Management Console.
     * `locked_date` = tanggal bisnis sebelumnya yang masih punya transaksi tetapi belum di-EOD.
     * `posting_date` = tanggal bisnis tempat transaksi baru dicatat (dan menjadi posting date SAP):
     * `locked_date` bila ada, selain itu tanggal hari ini.
     * `closed` = EOD tanggal posting sudah ditutup sehingga transaksi terkunci sampai dibuka ulang.
     *
     * @return array{business_date: string, posting_date: string, timezone: string, server_time: string, locked_date: ?string, closed: bool}
     */
    function store_business_status(?string $storeId): array
    {
        $businessDate = company_today();
        $lockedDate = $storeId ? StoreClosure::unclosedPriorDate($storeId, $businessDate) : null;
        $postingDate = $lockedDate ?? $businessDate;

        return [
            'business_date' => $businessDate,
            'posting_date' => $postingDate,
            'timezone' => company_timezone(),
            'server_time' => now()->toIso8601String(),
            'locked_date' => $lockedDate,
            'closed' => $storeId && StoreClosure::isClosed($storeId, $postingDate),
        ];
    }
}

if (! function_exists('login_greeting')) {
    /**
     * Sapaan menurut jam lokal perusahaan: 06.00-09.59 pagi, 10.00-14.59 siang,
     * 15.00-17.59 sore, selebihnya malam.
     */
    function login_greeting($at = null): string
    {
        $hour = (int) ($at ? Carbon::parse($at) : now())->setTimezone(company_timezone())->format('G');

        return match (true) {
            $hour >= 6 && $hour < 10 => 'Selamat pagi',
            $hour >= 10 && $hour < 15 => 'Selamat siang',
            $hour >= 15 && $hour < 18 => 'Selamat sore',
            default => 'Selamat malam',
        };
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
