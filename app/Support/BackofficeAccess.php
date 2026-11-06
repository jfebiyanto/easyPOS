<?php

namespace App\Support;

use App\Models\User;

/**
 * Matriks akses backoffice berdasarkan nama rute dan metode HTTP.
 *
 * Owner bebas. Store Manager: halaman di CLOSED tertutup total, tulis hanya di WRITABLE,
 * selebihnya hanya baca. Rute admin baru otomatis tidak bisa ditulis Store Manager
 * sampai didaftarkan di WRITABLE.
 */
class BackofficeAccess
{
    /**
     * Awalan nama rute yang tertutup total untuk Store Manager (baca dan tulis).
     *
     * @var list<string>
     */
    public const CLOSED = ['admin.company.', 'admin.numberings.', 'admin.sap-import.', 'admin.sap-postings.'];

    /**
     * Awalan nama rute yang boleh ditulis Store Manager (datanya tetap dibatasi ke gerainya).
     *
     * @var list<string>
     */
    public const WRITABLE = ['admin.eod.', 'admin.stock-review.', 'admin.stock-adjustments.', 'admin.stock-transfer-shipments.', 'admin.stock-transfer-receipts.', 'admin.stock-transfer-returns.', 'admin.deliveries.'];

    /**
     * Rute GET berakhiran .edit yang sebenarnya halaman utama (bukan formulir ubah).
     *
     * @var list<string>
     */
    public const READABLE_FORMS = ['admin.promotion-hierarchy.edit'];

    public static function allows(User $user, ?string $routeName, string $method): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        if (! $user->isStoreManager()) {
            return false;
        }

        $method = strtoupper($method);
        $isRead = in_array($method, ['GET', 'HEAD'], true);

        if (! $routeName) {
            return $isRead;
        }

        if (self::matches($routeName, self::CLOSED)) {
            return false;
        }

        if (self::matches($routeName, self::WRITABLE)) {
            return true;
        }

        return $isRead && ! self::isForm($routeName);
    }

    /**
     * True bila halaman ini hanya baca bagi user (tombol tulis disembunyikan).
     */
    public static function isReadOnly(User $user, ?string $routeName): bool
    {
        if ($user->isOwner()) {
            return false;
        }

        return ! $routeName || ! self::matches($routeName, self::WRITABLE);
    }

    /**
     * Apakah halaman (rute GET) ini tampil di menu user.
     */
    public static function visible(User $user, string $routeName): bool
    {
        return self::allows($user, $routeName, 'GET');
    }

    /**
     * @param  list<string>  $prefixes
     */
    private static function matches(string $routeName, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isForm(string $routeName): bool
    {
        if (in_array($routeName, self::READABLE_FORMS, true)) {
            return false;
        }

        return str_ends_with($routeName, '.create') || str_ends_with($routeName, '.edit');
    }
}
