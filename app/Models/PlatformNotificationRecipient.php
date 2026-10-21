<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlatformNotificationRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An address that is emailed about one kind of platform event. Any address,
 * not only staff accounts, so the right people hear even without a login.
 *
 * @property string $topic
 * @property string $email
 */
final class PlatformNotificationRecipient extends Model
{
    /** @use HasFactory<PlatformNotificationRecipientFactory> */
    use HasFactory;

    /** New Enterprise enquiries from the Enterprise page. */
    public const string ENTERPRISE_LEADS = 'enterprise_leads';

    protected $connection = 'landlord';

    protected $fillable = ['topic', 'email'];

    /**
     * @return list<string>
     */
    public static function emailsFor(string $topic): array
    {
        return self::query()->where('topic', $topic)->orderBy('id')->pluck('email')->all();
    }
}
