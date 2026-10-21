<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Reads the TXT records published for a DNS name. A contract so the proof that
 * an organisation owns a domain can be tested without the network.
 */
interface DnsTxtResolver
{
    /**
     * @return list<string> the text of each TXT record at this name
     */
    public function txt(string $name): array;
}
