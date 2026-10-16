<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'landlord';

    public function up(): void
    {
        $connection = Schema::connection('landlord')->getConnection();
        $connection->statement('LOCK TABLE members IN SHARE ROW EXCLUSIVE MODE');
        $connection->statement('DROP INDEX members_tenant_canonical_identity_unique');
        Schema::connection('landlord')->table('members', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'phone', 'name']);
        });
    }

    public function down(): void
    {
        $connection = Schema::connection('landlord')->getConnection();
        $connection->statement('LOCK TABLE members IN SHARE ROW EXCLUSIVE MODE');
        [$name,$phone] = $this->canonicalExpressions();
        if ($connection->selectOne("SELECT 1 FROM members WHERE phone IS NOT NULL GROUP BY tenant_id, ($name), ($phone) HAVING COUNT(*) > 1 LIMIT 1") !== null) {
            throw new RuntimeException('Reviewed members now share name and phone. Resolve these records before rollback; no member has been deleted or merged.');
        }
        Schema::connection('landlord')->table('members', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'phone', 'name']);
        });
        $connection->statement("CREATE UNIQUE INDEX members_tenant_canonical_identity_unique ON members (tenant_id, ($name), ($phone)) WHERE phone IS NOT NULL");
    }

    /** @return array{string,string} */
    private function canonicalExpressions(): array
    {
        $connection = Schema::connection('landlord')->getConnection();
        $whitespace = "\u{0009}\u{000A}\u{000B}\u{000C}\u{000D} \u{0085}\u{00A0}\u{1680}\u{2000}\u{2001}\u{2002}\u{2003}\u{2004}\u{2005}\u{2006}\u{2007}\u{2008}\u{2009}\u{200A}\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}";
        $invisible = preg_replace_callback('/\\\\x\{([0-9A-Fa-f]+)\}/', fn (array $match): string => mb_chr((int) hexdec($match[1])), Str::INVISIBLE_CHARACTERS);
        $trimName = $connection->getPdo()->quote($whitespace.$invisible);
        $squish = $connection->getPdo()->quote('['.$whitespace."\u{3164}\u{1160}".']+');
        $trimPhone = $connection->getPdo()->quote($whitespace);
        $name = "lower(regexp_replace(btrim(name, $trimName), $squish, ' ', 'g'))";
        $digits = "regexp_replace(phone, '[^0-9]', '', 'g')";
        $international = "CASE WHEN left($digits, 2) = '00' THEN substring($digits FROM 3) WHEN left($digits, 1) = '0' THEN '233' || substring($digits FROM 2) ELSE $digits END";
        $phone = "CASE WHEN length($international) BETWEEN 10 AND 15 THEN ($international) ELSE btrim(phone, $trimPhone) END";

        return [$name, $phone];
    }
};
